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
    isClassified,
} from './tree-model.js';
import {
    layerRename, layerSetVisible, layerSetOpacity, layerSetLocked, layerSetStyle,
    layerSetZoomRange, layerRemoveFromMap, layerGroup, layerUngroup, layerReorder,
    layerCreate, layerSetClassState,
} from '../store/commands/layer.js';
import { classStyle } from '../map/style/classify.js';
import { between } from '../lib/sort-key.js';
import { attachTreeDrag } from './tree-dnd.js';
import { confirmAction } from './confirm.js';

/** How long a typeahead buffer survives between keystrokes. */
const TYPEAHEAD_MS = 800;

export class LayerTree {
    /**
     * @param {Object} options
     * @param {HTMLElement} options.container
     * @param {Object} options.strings translated labels, from the bootstrap
     * @param {Function} options.onChanged called when the drawn set may have changed
     * @param {Function} options.onZoomTo called with a layer to fit its extent
     * @param {Function} options.onClassify called with a row to split its layer
     * @param {Function} options.onClassPreview called with a placement whose
     *        class paints changed without a command — an opacity drag
     * @param {Function} options.zoom () => the map's current zoom
     */
    constructor({
        container, strings, onChanged,
        onZoomTo = null, onRestyle = null, onAddLayer = null, onOpacityPreview = null,
        onIsolate = null, onOpenMaps = null, onCollapse = null, onRenameMap = null,
        onClassify = null, onClassPreview = null, onAddOverlay = null, onEditOverlay = null,
        onAttributes = null, onSelectionChange = null,
        zoom = () => null, zoomLimits = () => ({ min: 0, max: 22 }),
    }) {
        this.container = container;
        this.strings = strings;
        this.onChanged = onChanged;
        this.onZoomTo = onZoomTo;
        this.onRestyle = onRestyle;
        this.onOpacityPreview = onOpacityPreview;
        // Opening the field picker, and repainting one class mid-drag. Both
        // belong to the editor: the tree knows what was asked for, not how the
        // renderer is told.
        this.onClassify = onClassify;
        this.onClassPreview = onClassPreview;
        this.onAddOverlay = onAddOverlay;
        this.onEditOverlay = onEditOverlay;
        this.onAttributes = onAttributes;
        // Which rows are selected decides whether there is anywhere to draw,
        // so the toolbar has to hear about it. The tree knows the selection;
        // what that means for the toolbar is the editor's business.
        this.onSelectionChange = onSelectionChange;
        this.onIsolate = onIsolate;
        this.onCollapse = onCollapse;
        this.onRenameMap = onRenameMap;
        this.collapsedSection = false;
        this.zoom = zoom;
        this.zoomLimits = zoomLimits;
        this.isolating = false;

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

        // Renamed in place, like a layer. There is no other way to rename a
        // map, and a modal for one text field would be a heavier gesture than
        // the thing it changes.
        this.mapName = el('h2', {
            class: 'gis-tree-mapname',
            tabindex: '0',
            role: 'button',
            title: strings.renameMap,
            ondblclick: () => this.startMapRename(),
            onkeydown: (event) => {
                if (event.key === 'Enter' || event.key === 'F2') {
                    event.preventDefault();
                    this.startMapRename();
                }
            },
        });

        // The map you are in, and the way to a different one. Choosing a map
        // is not an action on the map's chrome, so it belongs next to the
        // map's name rather than in the toolbar over the canvas.
        const mapRow = el('div', { class: 'gis-tree-maprow' }, [
            this.mapName,
            el('button', {
                type: 'button',
                class: 'btn btn-sm btn-light gis-tree-maps-btn',
                title: strings.maps,
                'aria-label': strings.maps,
                onclick: () => onOpenMaps?.(),
            }, [el('i', { class: 'la la-map', 'aria-hidden': 'true' })]),
        ]);

        this.body = el('div', { class: 'gis-tree-body', id: 'gis-tree-body' }, [
            this.filterInput,
            this.scroller,
        ]);

        this.root = el('div', { class: 'gis-tree' }, [
            mapRow,
            el('hr'),
            el('div', { class: 'gis-tree-head' }, [
                // The whole title is the toggle, as it is on the map controls
                // below — two sections in one column that behave differently
                // would be two things to learn.
                this.sectionToggle = el('button', {
                    type: 'button',
                    class: 'gis-section-toggle gis-tree-title',
                    'aria-expanded': 'true',
                    'aria-controls': 'gis-tree-body',
                    onclick: () => {
                        this.setSectionCollapsed(!this.collapsedSection);
                        this.onCollapse?.(this.collapsedSection);
                    },
                }, [
                    this.sectionCaret = el('i', { class: 'la la-caret-down', 'aria-hidden': 'true' }),
                    el('span', { text: strings.layers }),
                ]),

                // The things you do TO the tree, where the tree is. They were
                // in the map toolbar, which is for the map.
                el('div', { class: 'gis-tree-actions btn-group btn-group-sm' }, [
                    el('button', {
                        type: 'button',
                        class: 'btn btn-light',
                        title: strings.addLayer,
                        'aria-label': strings.addLayer,
                        'aria-haspopup': 'true',
                        onclick: (event) => this.openAddMenu(event.currentTarget, onAddLayer),
                    }, [el('i', { class: 'la la-plus', 'aria-hidden': 'true' })]),
                    // Isolate acts on the tree's selection, so it belongs
                    // beside the tree's other actions rather than in the map
                    // control panel, where it had nothing to select from.
                    this.isolateButton = el('button', {
                        type: 'button',
                        class: 'btn btn-light gis-tree-isolate',
                        title: strings.isolateHint,
                        'aria-label': strings.isolate,
                        'aria-pressed': 'false',
                        onclick: () => this.toggleIsolate(),
                    }, [el('i', { class: 'la la-eye', 'aria-hidden': 'true' })]),
                    el('button', {
                        type: 'button',
                        class: 'btn btn-light gis-tree-group-btn',
                        title: strings.groupSelected,
                        'aria-label': strings.groupSelected,
                        onclick: () => this.groupSelection(),
                    }, [el('i', { class: 'la la-object-group', 'aria-hidden': 'true' })]),
                ]),
            ]),
            this.body,
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

    /**
     * Edit the map's name where it is written.
     *
     * The same gesture as a layer rename — double-click, or Enter on the
     * focused name — because they are the same kind of change and learning one
     * should teach the other.
     */
    startMapRename() {
        if (!this.onRenameMap || this.mapName.hidden) {
            return;
        }

        const current = this.mapName.textContent;

        const input = el('input', {
            type: 'text',
            class: 'form-control form-control-sm gis-tree-mapname-input',
            value: current,
            'aria-label': this.strings.renameMap,
        });

        this.mapName.hidden = true;
        this.mapName.after(input);
        input.focus();
        input.select();

        let done = false;

        const finish = async (commit) => {
            if (done) {
                return;
            }

            done = true;

            const value = input.value.trim();

            input.remove();
            this.mapName.hidden = false;

            if (commit && value !== '' && value !== current) {
                // Optimistic, then corrected: the server owns the slug, and
                // may hand back a name it had to disambiguate.
                this.mapName.textContent = value;

                const applied = await this.onRenameMap(value);

                this.mapName.textContent = applied ?? current;
            }
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
     * Fold the layer list away, keeping the map's name and the actions.
     *
     * The header, its buttons and the map name stay: collapsing the list
     * should not take away the control that adds to it. Only the filter and
     * the rows go.
     */
    setSectionCollapsed(collapsed) {
        this.collapsedSection = collapsed;
        this.body.hidden = collapsed;
        this.root.classList.toggle('is-collapsed', collapsed);
        this.sectionToggle.setAttribute('aria-expanded', String(!collapsed));
        this.sectionCaret.className = `la la-caret-${collapsed ? 'right' : 'down'}`;

        // The recycler sized itself against a container that has just changed
        // height, so what it thinks is on screen is no longer what is.
        if (!collapsed) {
            this.list.refresh();
        }
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
        this.mapName.textContent = store.state.map.name ?? '';
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
        // Only a sublayer row shows it, but it is built once with the rest of
        // the pooled node: creating it on demand would allocate per scroll.
        const swatch = el('span', { class: 'gis-tree-swatch', 'aria-hidden': 'true' });
        const name = el('span', { class: 'gis-tree-name' });
        const badges = el('span', { class: 'gis-tree-badges' });
        const menu = el('button', {
            type: 'button',
            class: 'gis-tree-menu-btn',
            tabindex: '-1',
            'aria-haspopup': 'true',
        }, [el('i', { class: 'la la-ellipsis-h', 'aria-hidden': 'true' })]);

        const row = el('div', { class: 'gis-tree-row', role: 'treeitem', tabindex: '-1' }, [
            twisty, check, swatch, name, badges, menu,
        ]);

        row.__parts = { twisty, check, swatch, name, badges, menu };

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

    /**
     * The data row a pooled node currently shows.
     *
     * The node's own dataset is checked against the row it resolves to, and a
     * disagreement answers null rather than the row. Nodes are recycled, so an
     * index that has gone stale does not point at nothing — it points at a
     * DIFFERENT, perfectly valid row, and a click on a sublayer's checkbox then
     * silently hides a whole layer somewhere else in the tree. That is a
     * failure with no error and no obvious symptom, which is worth one
     * comparison per click to rule out.
     */
    rowAt(node) {
        const row = this.rows[Number(node.dataset.index)] ?? null;

        if (!row) {
            return null;
        }

        const expected = row.kind === 'class' ? row.value : undefined;

        if (node.dataset.classValue !== expected) {
            return null;
        }

        return String(row.placement.id) === node.dataset.placementId ? row : null;
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

        if (row.kind === 'class') {
            this.renderClassRow(node, row);

            return;
        }

        delete node.dataset.classValue;
        node.classList.remove('is-class');

        const { twisty, check, swatch, name, badges, menu } = node.__parts;
        const group = isGroup(this.store.state, row.placement);
        const mayEdit = this.mayEdit(row);

        swatch.hidden = true;

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

            const label = this.collapsed.has(row.placement.id) ? this.strings.expand : this.strings.collapse;

            twisty.setAttribute('aria-label', label);
            twisty.setAttribute('title', label);
        } else {
            node.removeAttribute('aria-expanded');
            twisty.hidden = true;
        }

        const state = group ? groupCheckState(this.store.state, row.placement, this.index) : row.placement.visible;

        check.checked = state === true;
        check.indeterminate = state === null;
        check.setAttribute('aria-label', `${this.strings.visible}: ${row.layer.name}`);
        check.setAttribute('title', this.strings.visible);

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
        menu.setAttribute('title', this.strings.layerActions);
    }

    /**
     * One sublayer: a checkbox, its colour and its label.
     *
     * Rendered into the same pooled node as any other row, so it must undo
     * everything a layer row sets — a recycled node still carries the twisty,
     * badges and aria state of whatever it showed last.
     */
    renderClassRow(node, row) {
        const { twisty, check, swatch, name, badges, menu } = node.__parts;
        const entry = row.classEntry;

        node.classList.add('is-class');
        node.dataset.classValue = row.value;
        node.style.setProperty('--gis-depth', String(row.depth));
        node.setAttribute('aria-level', String(row.depth + 1));
        node.setAttribute('aria-selected', 'false');
        node.classList.remove('is-selected', 'is-matched');
        node.removeAttribute('aria-expanded');

        twisty.hidden = true;

        // Dimmed when its own box is clear, and also when the layer above it is
        // hidden — a sublayer of an invisible layer is not on the map either.
        const parentVisible = effectiveVisible(this.store.state, row.placement, this.zoom());

        node.classList.toggle('is-dimmed', !parentVisible || entry.visible === false);

        check.checked = entry.visible !== false;
        check.indeterminate = false;
        check.setAttribute('aria-label', `${this.strings.visible}: ${row.label}`);
        check.setAttribute('title', this.strings.visible);

        swatch.hidden = false;

        // Through `classStyle`, never `entry.style?.x` — an unstyled class
        // arrives as `[]` and every key on it resolves to an array method.
        const style = classStyle(entry);

        swatch.style.background = style.fill ?? row.layer.style?.fill ?? 'transparent';
        swatch.style.borderColor = style.stroke ?? row.layer.style?.stroke ?? 'transparent';

        name.textContent = row.label;
        node.setAttribute('aria-label', `${row.layer.name}: ${row.label}`);

        clear(badges);

        menu.setAttribute('aria-label', `${this.strings.sublayerActions}: ${row.label}`);
        menu.setAttribute('title', this.strings.sublayerActions);
    }

    badge(icon, label) {
        return el('span', { class: 'gis-tree-badge', title: label, 'aria-label': label, role: 'img' },
            [el('i', { class: `la ${icon}`, 'aria-hidden': 'true' })]);
    }

    /**
     * Whether this map may change the layer itself.
     *
     * The placement's access AND the layer's lock, both of which the server
     * re-checks. Visibility and opacity are deliberately not gated on it: they
     * are this map's view of the layer, not a change to the layer.
     */
    mayEdit(row) {
        return this.mayManage(row) && !row.layer.locked;
    }

    /**
     * Whether this map may manage the placement, lock included.
     *
     * **The lock is not part of this test, and that is the whole point.** A
     * locked layer refuses every edit — including, if this were `mayEdit`, the
     * control that unlocks it, which would make the lock permanent for anyone
     * without database access. The server draws the same distinction
     * deliberately (`LayerSetLocked::authorize`), and the two have to agree or
     * the UI offers something the server refuses, or hides something it would
     * have allowed.
     */
    mayManage(row) {
        return row.placement.access !== 'read';
    }

    // ---- operations ------------------------------------------------------

    toggleCollapsed(row) {
        if (!row || row.kind === 'class' || !row.hasChildren) {
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
     * Toggle a node, and everything under it.
     *
     * **A group's checkbox writes its descendants' flags too.** The
     * specification originally set only the group's flag and let inheritance
     * do the rest, so that rechecking a group restored the previous per-child
     * state. In use that reads as broken: the children stay ticked while the
     * map shows nothing, and the one control that looks like "turn this lot
     * off" only half does.
     *
     * The cost is stated rather than hidden: rechecking a group now turns all
     * of its children on, including ones that were off before. That is the
     * trade the visible behaviour is worth, and it is what a tri-state
     * checkbox means everywhere else.
     */
    toggleVisible(row) {
        if (!row) {
            return;
        }

        if (row.kind === 'class') {
            this.setClassState(row, { visible: row.classEntry.visible === false });

            return;
        }

        const visible = isGroup(this.store.state, row.placement)
            ? groupCheckState(this.store.state, row.placement, this.index) !== true
            : !row.placement.visible;

        const targets = [row.placement, ...descendantsOf(this.store.state, row.placement.id, this.index)];

        for (const placement of targets) {
            if (placement.visible === visible) {
                continue;
            }

            this.store.commit(layerSetVisible({
                id: placement.id,
                version: placement.version,
                visible,
            }));
        }

        this.rebuild();
        this.onChanged();
    }

    /**
     * Show an opacity while the slider is moving, without recording it.
     *
     * A range input fires `input` on every pixel of the drag. Committing there
     * sent a command, a server round trip and a full reconcile of the drawn
     * set per pixel — for a control whose whole value is that it responds
     * continuously. So the drag only repaints, and `commitOpacity` records the
     * value the user actually settled on.
     */
    previewOpacity(row, opacity) {
        const placement = this.store.state.placements[row.placement.id];

        if (!placement) {
            return;
        }

        placement.opacity = opacity;
        this.onOpacityPreview?.(this.store.state);
    }

    /**
     * One command, for the value the user let go on.
     *
     * @param {number} from where the drag started
     * @param {number} to where it ended
     */
    commitOpacity(row, from, to) {
        const placement = this.store.state.placements[row.placement.id];

        if (!placement || from === to) {
            return;
        }

        // Wound back to where the drag started before committing, because the
        // preview has already moved the local value — and a command applied to
        // its own result produces an inverse that undoes to the preview rather
        // than to where the layer actually was.
        placement.opacity = from;

        this.store.commit(layerSetOpacity({
            id: placement.id,
            version: placement.version,
            opacity: to,
        }));

        this.onChanged();
    }

    /**
     * Fill and line colour.
     *
     * `layer.setStyle` replaces the whole style object rather than patching a
     * key, because a half-applied style — a graduated ramp with no field —
     * renders nothing. So the current style is spread and the one changed key
     * written over it.
     *
     * **Style is layer-level, so this changes the layer in every map that
     * shows it.** That is by design, and it is also why this control is hidden
     * for a locked layer — which is every imported one.
     *
     * This comment used to predict that a per-map override would become a
     * column on the placement "if it turns out to be wanted". It turned out to
     * be wanted in S5d: `gis_map_layer.classification` carries a colour per
     * class, so the cadastre can be recoloured in one map without being
     * recoloured in all of them. A whole-layer per-map colour still is not a
     * thing, and splitting the layer is the better answer to wanting one.
     */
    setStyleKey(row, key, value) {
        const style = { ...(row.layer.style ?? {}), [key]: value };

        this.store.commit(layerSetStyle({
            id: row.layer.id,
            version: row.layer.version,
            style,
        }));

        // Repainted in place. The features are already loaded and their paths
        // already built; only the colour differs.
        this.onRestyle?.(row.layer.id, style);
    }

    startRename(row, node) {
        // A sublayer has no name of its own to change: its label comes from
        // the value in the data.
        if (!row || row.kind === 'class' || !this.mayEdit(row)) {
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

    /**
     * Make a layer, or place one that already exists.
     *
     * Two different operations behind one button, because they answer the same
     * question — "I want another layer here" — and separating them into two
     * icons would ask the user to know the difference before they have made
     * the choice. "Add from library" deliberately does not say "import":
     * import means reading a file, and is v2.
     */
    /**
     * Ask for an image file, and hand it to the editor to upload and place.
     *
     * The input is created, used and thrown away rather than kept in the page.
     * A persistent `<input type="file">` remembers its last selection, so
     * picking the same plan twice in a row fires no `change` event at all and
     * looks like the button has stopped working.
     */
    pickOverlayImage() {
        const input = el('input', {
            type: 'file',
            accept: 'image/png,image/jpeg',
            style: 'display:none',
        });

        input.addEventListener('change', () => {
            const file = input.files?.[0];

            input.remove();

            if (file) {
                this.onAddOverlay?.(file);
            }
        });

        document.body.append(input);
        input.click();
    }

    openAddMenu(anchor, onAddLayer) {
        this.closeMenu();

        const item = (label, handler, icon) => el('button', {
            type: 'button',
            class: 'dropdown-item',
            onclick: () => {
                this.closeMenu();
                handler();
            },
        }, [el('i', { class: `la ${icon}`, 'aria-hidden': 'true' }), ` ${label}`]);

        this.menu = el('div', { class: 'dropdown-menu show gis-tree-menu', role: 'menu' }, [
            item(this.strings.newLayer, () => this.createLayer('vector'), 'la-draw-polygon'),
            item(this.strings.newGroup, () => this.createLayer('group'), 'la-folder'),
            el('div', { class: 'dropdown-divider' }),
            item(this.strings.addFromLibrary, () => onAddLayer?.(), 'la-book'),
            item(this.strings.addImageOverlay, () => this.pickOverlayImage(), 'la-image'),
        ]);

        const box = anchor.getBoundingClientRect();

        this.menu.style.top = `${box.bottom}px`;
        this.menu.style.left = `${box.left}px`;

        document.body.append(this.menu);

        this.dismiss = (event) => {
            if (!this.menu.contains(event.target)) {
                this.closeMenu();
            }
        };

        window.setTimeout(() => document.addEventListener('pointerdown', this.dismiss), 0);
    }

    /**
     * An empty layer or group, owned by this map.
     *
     * It lands at the TOP of the tree, not the bottom: a layer you have just
     * made is the one you are about to draw in, and the top of the tree is the
     * top of the map.
     *
     * **It is created with a default name and then renamed inline**, rather
     * than asking for a name first. `window.prompt` blocks the page, cannot be
     * styled or translated beyond its label, and on a phone it is a system
     * sheet over the map. Renaming in place is also how every other rename in
     * this tree works, so it is one interaction rather than two.
     *
     * @param {'vector'|'group'} kind
     */
    async createLayer(kind = 'vector') {
        const first = childrenOf(this.store.state, null, this.index)[0] ?? null;
        const name = kind === 'group' ? this.strings.untitledGroup : this.strings.untitledLayer;

        this.store.commit(layerCreate({
            tempId: `lyr-${Date.now().toString(36)}`,
            name,
            kind,
            style: kind === 'group'
                ? {}
                : { stroke: '#2b6cb0', weight: 2, fill: '#63b3ed' },
            parentId: null,
            sortKey: between(null, first ? first.sortKey : null),
        }));

        // The layer and its placement both come back with server-assigned ids,
        // so the tree is re-read rather than guessed at.
        await this.onChanged({ reload: true });

        this.focusNewest(name);
    }

    /** The text a row shows, whichever kind it is. */
    rowText(row) {
        return row.kind === 'class' ? row.label : row.layer.name;
    }

    /** Select the layer just created and open its name for editing. */
    focusNewest(name) {
        const index = this.rows.findIndex((row) => row.kind !== 'class' && row.layer.name === name);

        if (index === -1) {
            return;
        }

        this.selection.clear();
        this.selection.add(this.rows[index].placement.id);
        this.focus(index);

        const node = this.list.pool.find((pooled) => Number(pooled.dataset.index) === index);

        if (node) {
            this.startRename(this.rows[index], node);
        }
    }

    /**
     * Solo the selection, and let go of it again.
     *
     * Client-only and never a command: the stored `visible` flags are left
     * exactly as they are, so turning it off restores what the user had rather
     * than turning everything on.
     */
    toggleIsolate() {
        this.isolating = !this.isolating && this.selection.size > 0;

        this.isolateButton.setAttribute('aria-pressed', String(this.isolating));
        this.isolateButton.classList.toggle('active', this.isolating);

        this.onIsolate?.(this.isolating ? true : null);
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

    async removeFromMap(row) {
        const confirmed = await confirmAction({
            title: this.strings.removeFromMap,
            text: this.strings.confirmRemove.replace(':name', row.layer.name),
            confirmLabel: this.strings.remove,
            cancelLabel: this.strings.cancel,
        });

        if (!confirmed) {
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
        // A sublayer is not a placement, so it cannot join a selection that
        // isolate, group and remove all act on.
        if (!row || row.kind === 'class') {
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
        this.onSelectionChange?.();
    }

    /**
     * Select the first row that can actually be drawn on.
     *
     * Called on load. Opening a map with one editable layer and no selection
     * meant the drawing tools were there, armed, and refused every click with
     * "select a layer first" — the commonest thing anyone does with a map they
     * just made. Nothing is selected when there is nothing to select, and the
     * toolbar is hidden in that case rather than lying about what it can do.
     */
    selectFirstEditable() {
        if (!this.store || this.selection.size > 0) {
            return false;
        }

        for (let i = 0; i < this.rows.length; i++) {
            const row = this.rows[i];

            if (row.kind === 'class' || !row.placement || !row.layer) {
                continue;
            }

            if (row.layer.kind === 'vector' && !row.layer.locked && row.placement.access !== 'read') {
                this.selection.clear();
                this.selection.add(row.placement.id);
                this.focused = i;
                this.list.render();
                this.onSelectionChange?.();

                return true;
            }
        }

        return false;
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

            // A sublayer row's text is its class label, not a layer name.
            if (this.rowText(this.rows[index]).toLowerCase().startsWith(needle)) {
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

        if (row.kind === 'class') {
            this.showMenu(this.classMenuItems(row), anchor);

            return;
        }

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

        // An image overlay is aligned by dragging its corners, which is a mode
        // rather than a dialog — so this turns the handles on and the menu
        // closes behind it.
        if (row.layer.kind === 'image' && this.onEditOverlay) {
            items.push(item(
                row.layer.locked ? this.strings.overlayLocked : this.strings.adjustOverlay,
                () => this.onEditOverlay(row.layer.locked ? null : row),
                'la-vector-square',
            ));
        }

        if (!group && row.layer.kind === 'vector' && this.onAttributes) {
            items.push(item(this.strings.attributes, () => this.onAttributes(row), 'la-table'));
        }

        if (!group && row.layer.extent !== null && this.onZoomTo) {
            items.push(item(this.strings.zoomToLayer, () => this.onZoomTo(row.layer), 'la-search-plus'));
        }

        if (mayEdit && !group) {
            items.push(this.colourItem(row));
        }

        // Splitting a layer is this map's reading of it, so it is offered
        // wherever visibility and opacity are — a locked, shared cadastral
        // layer is exactly the one worth splitting.
        if (!group && this.onClassify) {
            items.push(item(
                isClassified(row.placement) ? this.strings.resplit : this.strings.split,
                () => this.onClassify(row),
                'la-layer-group',
            ));
        }

        items.push(this.opacityItem(row));
        items.push(this.zoomRangeItem(row));

        if (this.mayManage(row)) {
            items.push(item(row.layer.locked ? this.strings.unlock : this.strings.lock,
                () => this.toggleLock(row), row.layer.locked ? 'la-unlock' : 'la-lock'));
        }

        if (group) {
            items.push(item(this.strings.ungroup, () => this.ungroup(row), 'la-object-ungroup'));
        }

        items.push(el('div', { class: 'dropdown-divider' }));
        items.push(item(this.strings.removeFromMap, () => this.removeFromMap(row), 'la-times'));

        this.showMenu(items, anchor);
    }

    /** Place a built menu under its anchor and arm its dismissal. */
    showMenu(items, anchor) {
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

    /**
     * A sublayer's menu: its two colours and its opacity.
     *
     * No rename, no lock, no remove. A sublayer is a value in the data, not a
     * thing this map made — what it is called comes from the features, and it
     * stops existing when the layer is split differently or not at all.
     *
     * Colour is offered regardless of `mayEdit`, unlike a layer's. A layer's
     * colour is the layer's, shared with every map showing it, so a locked
     * layer refuses it; a class's colour belongs to this map's classification,
     * which is this map's alone. That is the same line visibility and opacity
     * have always been on.
     */
    classMenuItems(row) {
        const entry = row.classEntry;
        const started = entry.opacity ?? 1;

        const swatch = (key, label, fallback) => {
            const input = el('input', {
                type: 'color',
                class: 'gis-tree-colour',
                value: normaliseColour(classStyle(entry)[key], row.layer.style?.[key] ?? fallback),
                'aria-label': `${label}: ${row.label}`,
            });

            input.addEventListener('change', () => this.setClassState(row, {
                style: { ...classStyle(entry), [key]: input.value },
            }));

            return el('label', { class: 'gis-tree-colour-field' }, [
                el('span', { text: label }),
                input,
            ]);
        };

        const slider = el('input', {
            type: 'range',
            min: '0',
            max: '100',
            value: String(Math.round(started * 100)),
            class: 'custom-range gis-tree-opacity',
            'aria-label': this.strings.opacity,
        });

        // `input` previews, `change` commits — the same rule the layer's own
        // slider follows, and for the same reason: a range input fires `input`
        // per pixel of the drag.
        slider.addEventListener('input', () => this.previewClassOpacity(row, Number(slider.value) / 100));
        slider.addEventListener('change', () => {
            this.previewClassOpacity(row, started);
            this.setClassState(row, { opacity: Number(slider.value) / 100 });
        });

        return [
            el('div', { class: 'dropdown-item-text gis-tree-colours' }, [
                swatch('fill', this.strings.fillColour, '#f0ad4e'),
                swatch('stroke', this.strings.lineColour, '#8a6d3b'),
            ]),
            el('div', { class: 'dropdown-item-text gis-tree-slider' }, [
                el('label', { text: this.strings.opacity }),
                slider,
            ]),
        ];
    }

    /**
     * Show a class's opacity while the slider moves, recording nothing.
     *
     * Mutates the held document in place and asks for a repaint, exactly as
     * the layer's own preview does. `setClassState` winds it back before
     * committing, so the command's inverse undoes to where the drag started
     * rather than to the last previewed frame.
     */
    previewClassOpacity(row, opacity) {
        row.classEntry.opacity = opacity;
        this.onClassPreview?.(row.placement);
    }

    /**
     * Commit one change to one sublayer.
     *
     * A patch, never the whole classification: two people dimming two
     * different categories must not collide, and rewriting all fourteen rows
     * would guarantee they do.
     */
    setClassState(row, patch) {
        this.store.commit(layerSetClassState({
            id: row.placement.id,
            version: row.placement.version,
            value: row.value,
            state: patch,
        }));

        this.rebuild();
        this.onChanged();
    }

    /**
     * Two native colour inputs.
     *
     * Native, because the platform's picker is keyboard operable, screen
     * reader labelled and familiar, and a hand-rolled swatch grid is three of
     * those things at best. `change` rather than `input`: a colour picker
     * fires continuously while the user drags through a gradient, and every
     * one of those would be a command on the wire and a step in the undo
     * stack.
     */
    colourItem(row) {
        const style = row.layer.style ?? {};

        const swatch = (key, label, fallback) => {
            const input = el('input', {
                type: 'color',
                class: 'gis-tree-colour',
                value: normaliseColour(style[key], fallback),
                'aria-label': `${label}: ${row.layer.name}`,
            });

            input.addEventListener('change', () => this.setStyleKey(row, key, input.value));

            return el('label', { class: 'gis-tree-colour-field' }, [
                el('span', { text: label }),
                input,
            ]);
        };

        return el('div', { class: 'dropdown-item-text gis-tree-colours' }, [
            swatch('fill', this.strings.fillColour, '#f0ad4e'),
            swatch('stroke', this.strings.lineColour, '#8a6d3b'),
        ]);
    }

    opacityItem(row) {
        const started = row.placement.opacity ?? 1;

        const slider = el('input', {
            type: 'range',
            min: '0',
            max: '100',
            value: String(Math.round(started * 100)),
            class: 'custom-range gis-tree-opacity',
            'aria-label': this.strings.opacity,
        });

        // `input` repaints, `change` records. A range input fires `input` on
        // every pixel of the drag, and a command per pixel is a round trip and
        // a reconcile per pixel.
        slider.addEventListener('input', () => this.previewOpacity(row, Number(slider.value) / 100));
        slider.addEventListener('change', () => this.commitOpacity(row, started, Number(slider.value) / 100));

        return el('div', { class: 'dropdown-item-text gis-tree-slider' }, [
            el('label', { text: this.strings.opacity }),
            slider,
        ]);
    }

    /**
     * The zoom band a layer is visible in.
     *
     * **The fields default to the map's own limits**, not to blanks. An empty
     * box says nothing about what the bound would be if you set one, and
     * "0 to 22" is not the answer either — the map's real range is whatever
     * the basemap allows, so those are the only numbers worth showing.
     *
     * A bound left at the map's limit is stored as `null`, which is what
     * "unbounded" means in `gis_map_layer`. Writing the limit instead would
     * pin the layer to today's basemap: swap in one that goes to 22 and every
     * layer would silently stop drawing above 20.
     */
    zoomRangeItem(row) {
        const limits = this.zoomLimits();

        const field = (value, fallback, label) => el('input', {
            type: 'number',
            min: String(limits.min),
            max: String(limits.max),
            class: 'form-control form-control-sm',
            value: String(value ?? fallback),
            'aria-label': label,
        });

        const min = field(row.placement.minZoom, limits.min, this.strings.minZoom);
        const max = field(row.placement.maxZoom, limits.max, this.strings.maxZoom);

        const bound = (input, limit) => {
            const value = input.value === '' ? limit : Number(input.value);

            return value === limit ? null : value;
        };

        const commit = () => this.setZoomRange(
            row,
            bound(min, limits.min),
            bound(max, limits.max),
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

/**
 * A colour a native `<input type="color">` will accept.
 *
 * The input takes `#rrggbb` and nothing else — it silently falls back to black
 * for a named colour, a short hex or `null`, which would then be written back
 * as a real style the next time the field fires. Anything it cannot take is
 * replaced by the layer's default rather than by black.
 */
function normaliseColour(value, fallback) {
    if (typeof value !== 'string') {
        return fallback;
    }

    const short = value.match(/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i);

    if (short) {
        return `#${short[1]}${short[1]}${short[2]}${short[2]}${short[3]}${short[3]}`;
    }

    return /^#[0-9a-f]{6}$/i.test(value) ? value.toLowerCase() : fallback;
}
