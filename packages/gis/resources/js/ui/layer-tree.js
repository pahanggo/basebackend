/**
 * The layer tree: what exists in this map, in what order, and what is drawn.
 *
 * The tree owns identity, order, visibility, opacity and z-order. Where you
 * are looking and what you are looking for belongs to the control panel — the
 * two are separate on purpose (specification section 8).
 *
 * Three things shape this file:
 *
 * - **Rows are recycled, never rebuilt.** Every row comes from
 *   `VirtualList`, so the DOM holds a screenful plus overscan whatever the
 *   tree's size. Nothing here may keep a reference to a row node between
 *   renders; the row for a placement is whichever pooled node currently shows
 *   it.
 * - **All tree arithmetic lives in `tree-model.js`.** Inheritance, tri-state,
 *   the cycle rule and where a drop lands are pure functions with their own
 *   tests. If a calculation appears here, it is in the wrong file.
 * - **A read-only placement renders its edit affordances as absent, not
 *   disabled.** The layer is simply not editable here, and a greyed control
 *   with a tooltip says something weaker and more confusing.
 */

import { el, clear } from '../lib/dom.js';
import { VirtualList, rowHeight } from './virtual-list.js';
import {
    flatten, isGroup, groupCheckState, effectiveVisible, childrenOf, descendantsOf, buildIndex,
} from './tree-model.js';
import {
    layerRename, layerSetVisible, layerSetOpacity, layerSetLocked,
    layerSetZoomRange, layerRemoveFromMap, layerGroup, layerUngroup, layerReorder,
} from '../store/commands/layer.js';
import { between } from '../lib/sort-key.js';
import { attachTreeDrag } from './tree-dnd.js';

/** How long a typeahead buffer survives between keystrokes. */
const TYPEAHEAD_MS = 800;

export class LayerTree {
    /**
     * @param {Object} options
     * @param {HTMLElement} options.container
     * @param {Object} options.strings translated labels, from the bootstrap
     * @param {Function} options.onChanged called when the drawn set may have changed
     * @param {Function} options.onZoomTo called with a layer to fit its extent
     * @param {Function} options.zoom () => the map's current zoom
     */
    constructor({ container, strings, onChanged, onZoomTo = null, zoom = () => null }) {
        this.container = container;
        this.strings = strings;
        this.onChanged = onChanged;
        this.onZoomTo = onZoomTo;
        this.zoom = zoom;

        this.store = null;
        this.rows = [];
        this.collapsed = new Set();
        this.selection = new Set();
        this.focused = null;
        this.filter = '';
        this.typeahead = { text: '', at: 0 };
        this.unsubscribe = [];

        this.filterInput = el('input', {
            type: 'search',
            class: 'form-control form-control-sm gis-tree-filter',
            placeholder: strings.filterLayers,
            'aria-label': strings.filterLayers,
        });

        this.scroller = el('div', { class: 'gis-tree-scroller' });

        this.list = new VirtualList({
            container: this.scroller,
            role: 'tree',
            height: rowHeight(),
            createRow: () => this.createRow(),
            renderRow: (node, index) => this.renderRow(node, index),
        });

        this.list.viewport.setAttribute('aria-label', strings.layers);
        this.list.viewport.setAttribute('aria-multiselectable', 'true');

        this.root = el('div', { class: 'gis-tree' }, [
            el('div', { class: 'gis-tree-head' }, [
                el('span', { class: 'gis-tree-title', text: strings.layers }),
                el('button', {
                    type: 'button',
                    class: 'btn btn-sm btn-light gis-tree-group-btn',
                    title: strings.groupSelected,
                    'aria-label': strings.groupSelected,
                    onclick: () => this.groupSelection(),
                }, [el('i', { class: 'la la-object-group', 'aria-hidden': 'true' })]),
            ]),
            this.filterInput,
            this.scroller,
        ]);

        container.append(this.root);

        let debounce = null;
        this.filterInput.addEventListener('input', () => {
            window.clearTimeout(debounce);
            debounce = window.setTimeout(() => {
                this.filter = this.filterInput.value;
                this.rebuild();
            }, 300);
        });

        this.scroller.addEventListener('keydown', (event) => this.onKeyDown(event));

        this.drag = attachTreeDrag(this);
    }

    /** Point the tree at a store. Called on every map switch. */
    attach(store) {
        this.detach();
        this.store = store;

        for (const topic of ['layers', 'tree']) {
            this.unsubscribe.push(store.subscribe(topic, () => this.rebuild()));
        }

        this.selection.clear();
        this.collapsed.clear();
        this.focused = null;
        this.rebuild();
    }

    detach() {
        for (const off of this.unsubscribe) {
            off();
        }

        this.unsubscribe = [];
    }

    rebuild() {
        if (!this.store) {
            return;
        }

        // One index per rebuild, handed to every question asked during the
        // render that follows. Rebuilt rather than kept, because an index that
        // outlives a mutation is a tree that disagrees with the store.
        this.index = buildIndex(this.store.state);
        this.rows = flatten(this.store.state, {
            collapsed: this.collapsed,
            filter: this.filter,
            index: this.index,
        });
        this.list.setCount(this.rows.length);
    }

    /** A pooled row. Built once; refilled on every recycle. */
    createRow() {
        const twisty = el('button', { type: 'button', class: 'gis-tree-twisty', tabindex: '-1' });
        const check = el('input', { type: 'checkbox', class: 'gis-tree-check' });
        const name = el('span', { class: 'gis-tree-name' });
        const badges = el('span', { class: 'gis-tree-badges' });
        const menu = el('button', {
            type: 'button',
            class: 'gis-tree-menu-btn',
            tabindex: '-1',
            'aria-haspopup': 'true',
        }, [el('i', { class: 'la la-ellipsis-h', 'aria-hidden': 'true' })]);

        const row = el('div', { class: 'gis-tree-row', role: 'treeitem', tabindex: '-1' }, [
            twisty, check, name, badges, menu,
        ]);

        row.__parts = { twisty, check, name, badges, menu };

        twisty.addEventListener('click', (event) => {
            event.stopPropagation();
            this.toggleCollapsed(this.rowAt(row));
        });

        check.addEventListener('change', (event) => {
            event.stopPropagation();
            this.toggleVisible(this.rowAt(row));
        });

        menu.addEventListener('click', (event) => {
            event.stopPropagation();
            this.openMenu(this.rowAt(row), menu);
        });

        row.addEventListener('pointerdown', (event) => {
            if (event.target === check || event.target === twisty) {
                return;
            }

            this.select(this.rowAt(row), event);
        });

        row.addEventListener('dblclick', () => this.startRename(this.rowAt(row), row));

        return row;
    }

    /** The data row a pooled node currently shows. */
    rowAt(node) {
        return this.rows[Number(node.dataset.index)] ?? null;
    }

    renderRow(node, index) {
        const row = this.rows[index];

        if (!row) {
            node.hidden = true;

            return;
        }

        node.hidden = false;
        node.dataset.index = String(index);
        node.dataset.placementId = String(row.placement.id);

        const { twisty, check, name, badges, menu } = node.__parts;
        const group = isGroup(this.store.state, row.placement);
        const mayEdit = this.mayEdit(row);

        node.style.setProperty('--gis-depth', String(row.depth));
        node.setAttribute('aria-level', String(row.depth + 1));
        node.setAttribute('aria-selected', String(this.selection.has(row.placement.id)));
        node.classList.toggle('is-selected', this.selection.has(row.placement.id));
        node.classList.toggle('is-matched', row.matched);

        // A layer inside its zoom band but hidden by an ancestor, and one
        // outside its band, are different states and read differently.
        node.classList.toggle('is-dimmed', !effectiveVisible(this.store.state, row.placement, this.zoom()));

        if (row.hasChildren) {
            node.setAttribute('aria-expanded', String(!this.collapsed.has(row.placement.id)));
            twisty.hidden = false;
            twisty.className = `gis-tree-twisty la ${this.collapsed.has(row.placement.id) ? 'la-caret-right' : 'la-caret-down'}`;
            twisty.setAttribute('aria-label', this.collapsed.has(row.placement.id) ? this.strings.expand : this.strings.collapse);
        } else {
            node.removeAttribute('aria-expanded');
            twisty.hidden = true;
        }

        const state = group ? groupCheckState(this.store.state, row.placement, this.index) : row.placement.visible;

        check.checked = state === true;
        check.indeterminate = state === null;
        check.setAttribute('aria-label', `${this.strings.visible}: ${row.layer.name}`);

        name.textContent = row.layer.name;
        node.setAttribute('aria-label', row.layer.name);

        clear(badges);

        if (row.layer.locked) {
            badges.append(this.badge('la-lock', this.strings.locked));
        }

        if (row.layer.shared) {
            badges.append(this.badge('la-share-alt', this.strings.shared));
        }

        if (!mayEdit && !group) {
            badges.append(this.badge('la-eye', this.strings.readOnly));
        }

        menu.setAttribute('aria-label', `${this.strings.layerActions}: ${row.layer.name}`);
    }

    badge(icon, label) {
        return el('span', { class: 'gis-tree-badge', title: label, 'aria-label': label, role: 'img' },
            [el('i', { class: `la ${icon}`, 'aria-hidden': 'true' })]);
    }

    /**
     * Whether this map may change the layer itself.
     *
     * The placement's access and the layer's lock, both of which the server
     * re-checks. Visibility and opacity are deliberately not gated on it: they
     * are this map's view of the layer, not a change to the layer.
     */
    mayEdit(row) {
        return row.placement.access !== 'read' && !row.layer.locked;
    }

    // ---- operations ------------------------------------------------------

    toggleCollapsed(row) {
        if (!row || !row.hasChildren) {
            return;
        }

        if (this.collapsed.has(row.placement.id)) {
            this.collapsed.delete(row.placement.id);
        } else {
            this.collapsed.add(row.placement.id);
        }

        this.rebuild();
    }

    /**
     * Toggle a node, and a group's descendants with it.
     *
     * Toggling a group off does **not** clear its children's own flags — it
     * sets the group's, and inheritance does the rest. That is what makes
     * rechecking restore the previous per-child state rather than turning
     * everything on (specification section 8).
     */
    toggleVisible(row) {
        if (!row) {
            return;
        }

        const placement = row.placement;

        this.store.commit(layerSetVisible({
            id: placement.id,
            version: placement.version,
            visible: !placement.visible,
        }));

        this.rebuild();
        this.onChanged();
    }

    setOpacity(row, opacity) {
        this.store.commit(layerSetOpacity({
            id: row.placement.id,
            version: row.placement.version,
            opacity,
        }));

        this.onChanged();
    }

    startRename(row, node) {
        if (!row || !this.mayEdit(row)) {
            return;
        }

        const input = el('input', {
            type: 'text',
            class: 'form-control form-control-sm gis-tree-rename',
            value: row.layer.name,
            'aria-label': this.strings.rename,
        });

        const { name } = node.__parts;

        name.replaceWith(input);
        input.focus();
        input.select();

        const finish = (commit) => {
            const value = input.value.trim();

            input.replaceWith(name);

            if (commit && value !== '' && value !== row.layer.name) {
                this.store.commit(layerRename({
                    id: row.layer.id,
                    version: row.layer.version,
                    name: value,
                }));
            }

            this.list.refresh();
        };

        input.addEventListener('blur', () => finish(true));
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                finish(true);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                finish(false);
            }

            event.stopPropagation();
        });
    }

    /**
     * Move a node one place among its siblings.
     *
     * The keyboard equivalent of a drag, and it goes through the same
     * `layer.reorder` command with a fractional key — one row written, not a
     * renumber (specification section 8).
     */
    move(row, delta) {
        if (!row) {
            return;
        }

        const siblings = childrenOf(this.store.state, row.placement.parentId ?? null);
        const index = siblings.findIndex((child) => child.id === row.placement.id);
        const target = index + delta;

        if (target < 0 || target >= siblings.length) {
            return;
        }

        const before = delta < 0
            ? (target > 0 ? siblings[target - 1].sortKey : null)
            : siblings[target].sortKey;

        const after = delta < 0
            ? siblings[target].sortKey
            : (target + 1 < siblings.length ? siblings[target + 1].sortKey : null);

        this.reorder(row.placement, row.placement.parentId ?? null, between(before, after));
    }

    /** Nest a node under the sibling above it — the keyboard's "drop into". */
    nestUnderPrevious(row) {
        if (!row) {
            return;
        }

        const siblings = childrenOf(this.store.state, row.placement.parentId ?? null);
        const index = siblings.findIndex((child) => child.id === row.placement.id);
        const previous = index > 0 ? siblings[index - 1] : null;

        if (!previous || !isGroup(this.store.state, previous)) {
            return;
        }

        const children = childrenOf(this.store.state, previous.id);
        const last = children.length > 0 ? children[children.length - 1].sortKey : null;

        this.collapsed.delete(previous.id);
        this.reorder(row.placement, previous.id, between(last, null));
    }

    reorder(placement, parentId, sortKey) {
        this.store.commit(layerReorder({
            id: placement.id,
            version: placement.version,
            parentId,
            sortKey,
        }));

        this.rebuild();
        this.focus(this.rows.findIndex((row) => row.placement.id === placement.id));
        this.onChanged();
    }

    groupSelection() {
        const ids = [...this.selection];

        if (ids.length === 0) {
            return;
        }

        // The group takes the position of the topmost node selected, so the
        // block does not jump to the bottom of the tree as it is wrapped.
        const first = this.rows.find((row) => ids.includes(row.placement.id));
        const parentId = first ? (first.placement.parentId ?? null) : null;
        const siblings = childrenOf(this.store.state, parentId);
        const last = siblings.length > 0 ? siblings[siblings.length - 1].sortKey : null;

        this.store.commit(layerGroup({
            tempId: `grp-${Date.now().toString(36)}`,
            name: this.strings.newGroup,
            placementIds: ids,
            parentId,
            sortKey: between(last, null),
        }));

        // The group's id is the server's to assign and every child's parent now
        // points at it, so the tree is re-read rather than guessed at.
        this.onChanged({ reload: true });
    }

    ungroup(row) {
        this.store.commit(layerUngroup({ id: row.placement.id, version: row.placement.version }));
        this.onChanged({ reload: true });
    }

    removeFromMap(row) {
        if (!window.confirm(this.strings.confirmRemove.replace(':name', row.layer.name))) {
            return;
        }

        this.store.commit(layerRemoveFromMap({
            id: row.placement.id,
            version: row.placement.version,
            layerId: row.layer.id,
        }));

        this.selection.delete(row.placement.id);
        this.rebuild();
        this.onChanged();
    }

    toggleLock(row) {
        this.store.commit(layerSetLocked({
            id: row.layer.id,
            version: row.layer.version,
            locked: !row.layer.locked,
        }));

        this.list.refresh();
    }

    setZoomRange(row, minZoom, maxZoom) {
        this.store.commit(layerSetZoomRange({
            id: row.placement.id,
            version: row.placement.version,
            minZoom,
            maxZoom,
        }));

        this.list.refresh();
        this.onChanged();
    }

    // ---- selection and focus ---------------------------------------------

    select(row, event = {}) {
        if (!row) {
            return;
        }

        const id = row.placement.id;

        if (event.shiftKey && this.focused !== null) {
            const from = Math.min(this.focused, this.rows.indexOf(row));
            const to = Math.max(this.focused, this.rows.indexOf(row));

            for (let i = from; i <= to; i += 1) {
                this.selection.add(this.rows[i].placement.id);
            }
        } else if (event.ctrlKey || event.metaKey) {
            if (this.selection.has(id)) {
                this.selection.delete(id);
            } else {
                this.selection.add(id);
            }
        } else {
            this.selection.clear();
            this.selection.add(id);
        }

        this.focus(this.rows.indexOf(row));
    }

    focus(index) {
        if (index < 0 || index >= this.rows.length) {
            return;
        }

        this.focused = index;
        this.list.scrollTo(index);
        this.list.refresh();

        const node = this.list.pool.find((row) => Number(row.dataset.index) === index);

        node?.focus({ preventScroll: true });
    }

    focusedRow() {
        return this.focused === null ? null : this.rows[this.focused] ?? null;
    }

    // ---- keyboard --------------------------------------------------------

    /**
     * Full keyboard operation, as the gate requires: arrows navigate and
     * expand, Home/End jump, typeahead focuses by name, Space toggles
     * visibility, ctrl+arrows reorder and nest.
     */
    onKeyDown(event) {
        const row = this.focusedRow();

        const moves = {
            ArrowDown: () => this.focus((this.focused ?? -1) + 1),
            ArrowUp: () => this.focus((this.focused ?? this.rows.length) - 1),
            Home: () => this.focus(0),
            End: () => this.focus(this.rows.length - 1),
        };

        if (event.ctrlKey || event.metaKey) {
            if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
                event.preventDefault();
                this.move(row, event.key === 'ArrowUp' ? -1 : 1);

                return;
            }

            if (event.key === 'ArrowRight') {
                event.preventDefault();
                this.nestUnderPrevious(row);

                return;
            }
        }

        if (moves[event.key]) {
            event.preventDefault();
            moves[event.key]();

            return;
        }

        if (event.key === 'ArrowRight' && row?.hasChildren && this.collapsed.has(row.placement.id)) {
            event.preventDefault();
            this.toggleCollapsed(row);

            return;
        }

        if (event.key === 'ArrowLeft' && row) {
            event.preventDefault();

            if (row.hasChildren && !this.collapsed.has(row.placement.id)) {
                this.toggleCollapsed(row);
            } else if (row.placement.parentId) {
                this.focus(this.rows.findIndex((other) => other.placement.id === row.placement.parentId));
            }

            return;
        }

        if (event.key === ' ') {
            event.preventDefault();
            this.toggleVisible(row);

            return;
        }

        if (event.key === 'F2') {
            event.preventDefault();

            const node = this.list.pool.find((other) => Number(other.dataset.index) === this.focused);

            if (node) {
                this.startRename(row, node);
            }

            return;
        }

        if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
            this.typeaheadTo(event.key);
        }
    }

    typeaheadTo(character) {
        const now = Date.now();

        this.typeahead.text = now - this.typeahead.at > TYPEAHEAD_MS
            ? character
            : this.typeahead.text + character;
        this.typeahead.at = now;

        const needle = this.typeahead.text.toLowerCase();
        const from = (this.focused ?? -1) + 1;

        // Wrap, so typing the same letter repeatedly cycles through matches
        // rather than stopping at the last one.
        for (let i = 0; i < this.rows.length; i += 1) {
            const index = (from + i) % this.rows.length;

            if (this.rows[index].layer.name.toLowerCase().startsWith(needle)) {
                this.focus(index);

                return;
            }
        }
    }

    // ---- context menu ----------------------------------------------------

    /**
     * The per-row operations.
     *
     * Built fresh each time and torn down on dismissal: a menu that outlives
     * the row it was opened from would act on whatever the pool recycled that
     * node into.
     */
    openMenu(row, anchor) {
        if (!row) {
            return;
        }

        this.closeMenu();

        const state = this.store.state;
        const group = isGroup(state, row.placement);
        const mayEdit = this.mayEdit(row);
        const items = [];

        const item = (label, handler, icon = null) => el('button', {
            type: 'button',
            class: 'dropdown-item',
            onclick: () => {
                this.closeMenu();
                handler();
            },
        }, [icon ? el('i', { class: `la ${icon}`, 'aria-hidden': 'true' }) : null, ` ${label}`]);

        if (mayEdit) {
            items.push(item(this.strings.rename, () => {
                const node = this.list.pool.find((other) => other.dataset.placementId === String(row.placement.id));

                if (node) {
                    this.startRename(row, node);
                }
            }, 'la-i-cursor'));
        }

        if (!group && row.layer.extent !== null && this.onZoomTo) {
            items.push(item(this.strings.zoomToLayer, () => this.onZoomTo(row.layer), 'la-search-plus'));
        }

        items.push(this.opacityItem(row));
        items.push(this.zoomRangeItem(row));

        if (mayEdit) {
            items.push(item(row.layer.locked ? this.strings.unlock : this.strings.lock,
                () => this.toggleLock(row), 'la-lock'));
        }

        if (group) {
            items.push(item(this.strings.ungroup, () => this.ungroup(row), 'la-object-ungroup'));
        }

        items.push(el('div', { class: 'dropdown-divider' }));
        items.push(item(this.strings.removeFromMap, () => this.removeFromMap(row), 'la-times'));

        this.menu = el('div', { class: 'dropdown-menu show gis-tree-menu', role: 'menu' }, items);

        const box = anchor.getBoundingClientRect();

        this.menu.style.top = `${box.bottom}px`;
        this.menu.style.left = `${Math.max(8, box.right - 220)}px`;

        document.body.append(this.menu);

        this.dismiss = (event) => {
            if (!this.menu.contains(event.target)) {
                this.closeMenu();
            }
        };

        window.setTimeout(() => document.addEventListener('pointerdown', this.dismiss), 0);
    }

    opacityItem(row) {
        const slider = el('input', {
            type: 'range',
            min: '0',
            max: '100',
            value: String(Math.round((row.placement.opacity ?? 1) * 100)),
            class: 'custom-range gis-tree-opacity',
            'aria-label': this.strings.opacity,
        });

        // On `input` while dragging, so the map follows the thumb; the command
        // coalesces in the undo stack rather than recording a step per pixel.
        slider.addEventListener('input', () => this.setOpacity(row, Number(slider.value) / 100));

        return el('div', { class: 'dropdown-item-text gis-tree-slider' }, [
            el('label', { text: this.strings.opacity }),
            slider,
        ]);
    }

    zoomRangeItem(row) {
        const min = el('input', {
            type: 'number', min: '0', max: '22', class: 'form-control form-control-sm',
            value: row.placement.minZoom ?? '', 'aria-label': this.strings.minZoom,
        });

        const max = el('input', {
            type: 'number', min: '0', max: '22', class: 'form-control form-control-sm',
            value: row.placement.maxZoom ?? '', 'aria-label': this.strings.maxZoom,
        });

        const commit = () => this.setZoomRange(
            row,
            min.value === '' ? null : Number(min.value),
            max.value === '' ? null : Number(max.value),
        );

        min.addEventListener('change', commit);
        max.addEventListener('change', commit);

        return el('div', { class: 'dropdown-item-text gis-tree-zoom-range' }, [
            el('label', { text: this.strings.zoomRange }),
            el('div', { class: 'gis-tree-zoom-inputs' }, [min, max]),
        ]);
    }

    closeMenu() {
        if (!this.menu) {
            return;
        }

        document.removeEventListener('pointerdown', this.dismiss);
        this.menu.remove();
        this.menu = null;
    }

    /** Every placement in the current selection, plus what is under a group. */
    selectedWithDescendants() {
        const ids = new Set(this.selection);

        for (const id of this.selection) {
            for (const child of descendantsOf(this.store.state, id)) {
                ids.add(child.id);
            }
        }

        return ids;
    }
}
