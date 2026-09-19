/**
 * The attribute table: the features on screen, as rows.
 *
 * **It shows what is loaded, which is the viewport — and it says so.** The
 * alternative is a paged, server-sorted table over a layer of 2.9 million
 * features, which is a different endpoint and a different session. Sorting a
 * viewport and calling it the layer would be the more comfortable lie, and the
 * one that produces a wrong answer nobody can see: a "smallest lot" that is
 * only the smallest of what happened to be on screen.
 *
 * Rows are virtualized through the same `virtual-list.js` the layer tree uses,
 * so only what is visible is in the DOM (specification §14).
 *
 * Attributes are not loaded by default — for the imported cadastre they are
 * more than half the payload — so opening the table re-reads its layer with
 * them, and closing it stops.
 */

import { el, clear } from '../lib/dom.js';
import { VirtualList, rowHeight } from './virtual-list.js';
import { featureUpdate } from '../store/commands/feature.js';

/** Sort states a column cycles through. */
const SORT = ['none', 'asc', 'desc'];

export class AttributeTable {
    /**
     * @param {Object} options
     * @param {HTMLElement} options.container the map shell
     * @param {Object} options.strings translated labels
     * @param {Function} options.onClose called when the dock is dismissed
     * @param {Function} options.onZoomTo called with a feature id to fly to it
     */
    constructor({ container, strings, onClose, onZoomTo = null, onSelect = null }) {
        this.onSelect = onSelect;
        this.strings = strings;
        this.onClose = onClose;
        this.onZoomTo = onZoomTo;

        this.store = null;
        this.layer = null;
        this.feed = null;
        this.columns = [];
        this.rows = [];
        this.sort = [];
        this.filters = new Map();

        this.title = el('strong', { class: 'gis-table-title' });
        this.note = el('span', { class: 'gis-table-note' });
        this.head = el('div', { class: 'gis-table-head' });
        this.scroller = el('div', { class: 'gis-table-scroller' });

        this.element = el('div', { class: 'gis-table', role: 'region', hidden: true }, [
            el('div', { class: 'gis-table-bar' }, [
                this.title,
                this.note,
                el('button', {
                    type: 'button',
                    class: 'gis-table-close',
                    'aria-label': strings.close,
                    onclick: () => this.close(),
                }, [el('i', { class: 'la la-times', 'aria-hidden': 'true' })]),
            ]),
            this.head,
            this.scroller,
        ]);

        container.append(this.element);

        this.list = new VirtualList({
            container: this.scroller,
            height: rowHeight(),
            role: 'rowgroup',
            createRow: () => this.createRow(),
            renderRow: (node, index) => this.renderRow(node, index),
        });
    }

    attach(store) {
        this.store = store;

        // The map and the table show one selection, so the table redraws when
        // it changes wherever it changed.
        store.subscribe('selection', () => this.list?.render());
    }

    /** Open on a layer, which is what makes its attributes worth loading. */
    open(layer, feed) {
        this.layer = layer;
        this.feed = feed;
        this.sort = [];
        this.filters = new Map();

        this.columns = (layer.attrSchema ?? [])
            .filter((field) => field && typeof field.name === 'string')
            .map((field) => ({ ...field }));

        this.title.textContent = layer.name;
        this.element.hidden = false;
        this.element.setAttribute('aria-label', `${this.strings.attributes}: ${layer.name}`);

        this.renderHead();
        this.refresh();
    }

    close() {
        this.element.hidden = true;
        this.layer = null;
        this.feed = null;
        this.onClose?.();
    }

    get isOpen() {
        return this.layer !== null;
    }

    /**
     * Rebuild the rows from what the feed holds.
     *
     * Called after every read, because panning changes which features are
     * loaded and the table is a view of exactly those.
     */
    refresh() {
        if (!this.feed?.entry || !this.feed.accumulator) {
            this.rows = [];
            this.list.setCount(0);
            this.note.textContent = this.strings.tableLoading;

            return;
        }

        const { ids } = this.feed.entry.geometry;
        const properties = this.feed.accumulator.properties ?? [];
        const count = this.feed.entry.geometry.count;
        const rows = [];

        for (let f = 0; f < count; f++) {
            rows.push({ feature: f, id: ids[f], attributes: properties[f] ?? {} });
        }

        this.rows = this.sorted(rows.filter((row) => this.matches(row)));
        this.list.setCount(this.rows.length);

        // Named as the viewport, every time. The count is the number the user
        // will quote, and a count that silently means something narrower than
        // it says is the failure this whole panel risks.
        this.note.textContent = this.strings.tableCount
            .replace(':shown', String(this.rows.length))
            .replace(':loaded', String(count));
    }

    /** Does a row survive every active column filter? */
    matches(row) {
        for (const [name, filter] of this.filters) {
            const value = row.attributes[name];

            if (filter.kind === 'text') {
                if (!String(value ?? '').toLowerCase().includes(filter.text)) {
                    return false;
                }

                continue;
            }

            const numeric = Number(value);

            if (!Number.isFinite(numeric)) {
                return false;
            }

            if (filter.min !== null && numeric < filter.min) {
                return false;
            }

            if (filter.max !== null && numeric > filter.max) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rows in sort order.
     *
     * Multi-column, most recently chosen first, so shift-clicking a second
     * column refines the first rather than replacing it.
     */
    sorted(rows) {
        if (this.sort.length === 0) {
            return rows;
        }

        const numericColumns = new Set(
            this.columns.filter((c) => c.type === 'number').map((c) => c.name),
        );

        return rows.sort((a, b) => {
            for (const { name, direction } of this.sort) {
                const left = a.attributes[name];
                const right = b.attributes[name];
                let order;

                if (numericColumns.has(name)) {
                    order = (Number(left) || 0) - (Number(right) || 0);
                } else {
                    // Natural, so "Lot 9" precedes "Lot 10" — which is how
                    // every lot number in this data is written.
                    order = String(left ?? '').localeCompare(String(right ?? ''), undefined, {
                        numeric: true,
                        sensitivity: 'base',
                    });
                }

                if (order !== 0) {
                    return direction === 'desc' ? -order : order;
                }
            }

            return 0;
        });
    }

    renderHead() {
        clear(this.head);

        for (const column of this.columns) {
            const state = this.sort.find((s) => s.name === column.name);

            const button = el('button', {
                type: 'button',
                class: `gis-table-column${state ? ` is-${state.direction}` : ''}`,
                title: column.name,
                onclick: (event) => this.toggleSort(column.name, event.shiftKey),
            }, [
                el('span', { class: 'gis-table-column-name', text: column.name }),
                state ? el('i', {
                    class: `la ${state.direction === 'asc' ? 'la-arrow-up' : 'la-arrow-down'}`,
                    'aria-hidden': 'true',
                }) : null,
            ]);

            const filter = el('input', {
                type: column.type === 'number' ? 'text' : 'search',
                class: 'gis-table-filter',
                placeholder: column.type === 'number' ? '10-500' : this.strings.filter,
                'aria-label': `${this.strings.filter}: ${column.name}`,
            });

            filter.addEventListener('input', () => this.setFilter(column, filter.value));

            this.head.append(el('div', { class: 'gis-table-cell is-head' }, [button, filter]));
        }
    }

    /**
     * Read a filter box.
     *
     * A numeric column takes a range, written the way anyone writes one: `10-500`,
     * `-50` for at most, `500-` for at least, or a bare number for exactly.
     */
    setFilter(column, text) {
        const value = text.trim();

        if (value === '') {
            this.filters.delete(column.name);
            this.refresh();

            return;
        }

        if (column.type !== 'number') {
            this.filters.set(column.name, { kind: 'text', text: value.toLowerCase() });
            this.refresh();

            return;
        }

        const range = value.match(/^(-?[\d.]*)\s*-\s*(-?[\d.]*)$/);

        if (range) {
            this.filters.set(column.name, {
                kind: 'range',
                min: range[1] === '' ? null : Number(range[1]),
                max: range[2] === '' ? null : Number(range[2]),
            });
        } else if (Number.isFinite(Number(value))) {
            this.filters.set(column.name, { kind: 'range', min: Number(value), max: Number(value) });
        }

        this.refresh();
    }

    toggleSort(name, additive) {
        const at = this.sort.findIndex((s) => s.name === name);
        const current = at === -1 ? 'none' : this.sort[at].direction;
        const next = SORT[(SORT.indexOf(current) + 1) % SORT.length];

        if (!additive) {
            this.sort = [];
        } else if (at !== -1) {
            this.sort.splice(at, 1);
        }

        if (next !== 'none') {
            this.sort.unshift({ name, direction: next });
        }

        this.renderHead();
        this.refresh();
    }

    createRow() {
        const row = el('div', { class: 'gis-table-row', role: 'row' });

        row.addEventListener('dblclick', (event) => {
            const cell = event.target.closest('.gis-table-cell');

            if (cell) {
                this.startEdit(row, cell);
            }
        });

        row.addEventListener('click', (event) => {
            if (event.detail !== 1) {
                return;
            }

            const data = this.rows[Number(row.dataset.index)];

            if (!data) {
                return;
            }

            // **Selecting and zooming are the same click**, deliberately. The
            // table is the accessible equivalent of the map (§18): what a
            // click on the map does — select it and bring it into view — is
            // what a click on the row has to do, or the two are different
            // interfaces to the same data rather than two views of it.
            this.onSelect?.(data, { additive: event.shiftKey });
            this.onZoomTo?.(data);
        });

        return row;
    }

    renderRow(node, index) {
        const row = this.rows[index];

        if (!row) {
            node.hidden = true;

            return;
        }

        node.hidden = false;
        node.dataset.index = String(index);
        node.dataset.featureId = String(row.id);

        // Rows are POOLED — the same node is reused for a different feature as
        // the list scrolls — so this has to be set on every render, not once
        // when the selection changes. A class left over from the previous
        // occupant is a row that reports the wrong thing selected.
        const selected = this.store?.state.selection?.has(row.id) ?? false;

        node.classList.toggle('is-selected', selected);
        node.setAttribute('aria-selected', String(selected));

        clear(node);

        for (const column of this.columns) {
            const value = row.attributes[column.name];

            node.append(el('div', {
                class: 'gis-table-cell',
                role: 'cell',
                'data-field': column.name,
                // `text`, never markup. Imported attributes are untrusted and
                // reach the DOM as text everywhere in this package.
                text: value === null || value === undefined ? '' : String(value),
            }));
        }
    }

    /**
     * Edit one cell in place.
     *
     * Committed as a property PATCH, which is what `feature.update` takes:
     * the keys sent are the keys written, so two people editing different
     * columns of the same row merge on the server rather than collide.
     */
    startEdit(node, cell) {
        const row = this.rows[Number(node.dataset.index)];
        const field = cell.dataset.field;

        if (!row || !field || this.layer?.locked) {
            return;
        }

        const column = this.columns.find((c) => c.name === field);
        const before = row.attributes[field];

        const input = el('input', {
            type: column?.type === 'number' ? 'number' : 'text',
            class: 'gis-table-editor',
            value: before === null || before === undefined ? '' : String(before),
        });

        clear(cell);
        cell.append(input);
        input.focus();
        input.select();

        const finish = (commit) => {
            const raw = input.value;

            clear(cell);
            cell.textContent = commit ? raw : (before ?? '');

            if (!commit) {
                return;
            }

            const value = column?.type === 'number'
                ? (raw === '' ? null : Number(raw))
                : (raw === '' ? null : raw);

            if (value === before) {
                return;
            }

            row.attributes[field] = value;

            this.store.commit(featureUpdate({
                id: row.id,
                layerId: this.layer.id,
                version: this.store.state.features[row.id]?.version ?? 1,
                properties: { [field]: value },
            }));
        };

        input.addEventListener('blur', () => finish(true));
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                input.blur();
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                input.removeEventListener('blur', finish);
                finish(false);
            }
        });
    }
}
