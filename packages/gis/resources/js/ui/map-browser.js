/**
 * The map browser.
 *
 * The editor always has exactly one map open, and this is how that choice is
 * made and changed. It opens automatically when the editor is reached without
 * one, and from the toolbar at any time.
 *
 * Server-side paged rather than virtualized: a user has tens of maps, not
 * thousands, and `virtual-list.js` here would be machinery with no load to
 * justify it. If that assumption ever proves wrong, the body of this one table
 * is a contained change (specification section 8).
 */

import { el, clear, relativeTime } from '../lib/dom.js';
import { getJson, postJson, deleteJson } from '../lib/http.js';
import { Modal } from './modal.js';

const SEARCH_DEBOUNCE_MS = 300;

export class MapBrowser {
    /**
     * @param {Object} options
     * @param {string} options.apiBase
     * @param {Object} options.strings
     * @param {Function} options.onLoad called with a map id the user chose
     * @param {Function} options.currentMapId
     * @param {string} options.clientId half of the idempotency key; stable per tab
     * @param {Function} [options.onRestore] issues `map.restore`; administrators only
     * @param {Function} [options.canRestore] whether to offer the deleted view
     */
    constructor({ apiBase, strings, onLoad, currentMapId, clientId, seq, onRestore = null, canRestore = () => false }) {
        this.apiBase = apiBase;
        this.strings = strings;
        this.onLoad = onLoad;
        this.currentMapId = currentMapId;

        // The client's one sequence counter. Creating a map is a unit of work
        // against the same `(clientId, seq)` key space the command queue uses,
        // so it must not count on its own.
        this.seq = seq;
        this.clientId = clientId;
        this.onRestore = onRestore;
        this.canRestore = canRestore;

        this.state = { q: '', page: 1, deleted: false, rows: [], meta: null, error: null };
        this.searchTimer = null;

        this.modal = new Modal({ id: 'gis-map-browser', title: strings.maps });
    }

    async open() {
        this.modal.show();

        await this.refresh();
    }

    async refresh() {
        try {
            const body = await getJson(`${this.apiBase}/maps`, {
                q: this.state.q,
                page: this.state.page,
                deleted: this.state.deleted ? 1 : 0,
            });

            this.state.rows = body.data;
            this.state.meta = body.meta;
            this.state.error = null;
        } catch (error) {
            this.state.rows = [];
            this.state.error = error.problem?.detail || error.message;
        }

        this.render();
    }

    render() {
        this.modal.setBody(this.controls(), this.table());
        this.modal.setFooter(this.creator());
    }

    controls() {
        const search = el('input', {
            type: 'search',
            class: 'form-control',
            placeholder: this.strings.searchMaps,
            value: this.state.q,
            'aria-label': this.strings.searchMaps,
            oninput: (event) => {
                // Debounced at 300 ms: the listing is a server round trip, and
                // one per keystroke is both wasteful and visibly jumpy.
                window.clearTimeout(this.searchTimer);

                const value = event.target.value;

                this.searchTimer = window.setTimeout(() => {
                    this.state.q = value;
                    this.state.page = 1;
                    this.refresh();
                }, SEARCH_DEBOUNCE_MS);
            },
        });

        const row = el('div', { class: 'form-row align-items-center mb-3' }, [
            el('div', { class: 'col' }, [search]),
        ]);

        if (this.canRestore()) {
            const id = 'gis-show-deleted';

            row.append(el('div', { class: 'col-auto' }, [
                el('div', { class: 'form-check' }, [
                    el('input', {
                        class: 'form-check-input',
                        type: 'checkbox',
                        id,
                        checked: this.state.deleted,
                        onchange: (event) => {
                            this.state.deleted = event.target.checked;
                            this.state.page = 1;
                            this.refresh();
                        },
                    }),
                    el('label', { class: 'form-check-label', for: id, text: this.strings.showDeleted }),
                ]),
            ]));
        }

        return row;
    }

    table() {
        if (this.state.error) {
            return el('div', { class: 'alert alert-danger', role: 'alert', text: this.state.error });
        }

        if (this.state.rows.length === 0) {
            return el('p', { class: 'text-muted mb-0', text: this.strings.noMaps });
        }

        const head = el('tr', {}, [
            el('th', { scope: 'col', text: this.strings.name }),
            el('th', { scope: 'col', class: 'text-right', text: this.strings.layers }),
            el('th', { scope: 'col', class: 'text-right', text: this.strings.features }),
            el('th', { scope: 'col', text: this.strings.updated }),
            el('th', { scope: 'col', class: 'text-right' }),
        ]);

        const body = el('tbody', {}, this.state.rows.map((row) => this.row(row)));

        return el('div', { class: 'table-responsive' }, [
            el('table', { class: 'table table-sm table-hover mb-0' }, [
                el('thead', {}, [head]),
                body,
            ]),
        ]);
    }

    row(map) {
        const isCurrent = map.id === this.currentMapId();

        // The current map is marked and not clickable: loading the map you are
        // already in would flush the queue and clear the undo stack for nothing.
        const name = isCurrent
            ? el('span', {}, [
                el('strong', { text: map.name }),
                ' ',
                el('span', { class: 'badge badge-secondary', text: this.strings.open }),
            ])
            : el('a', {
                href: '#',
                text: map.name,
                onclick: (event) => {
                    event.preventDefault();
                    this.load(map);
                },
            });

        return el('tr', {}, [
            el('td', {}, [name]),
            el('td', { class: 'text-right', text: String(map.layerCount) }),
            el('td', { class: 'text-right', text: map.featureCount.toLocaleString() }),
            el('td', { title: map.updatedAt || '', text: relativeTime(map.updatedAt) }),
            el('td', { class: 'text-right' }, [this.actions(map, isCurrent)]),
        ]);
    }

    actions(map, isCurrent) {
        const group = el('div', { class: 'btn-group btn-group-sm' });

        if (map.deletedAt) {
            if (this.canRestore() && this.onRestore !== null) {
                group.append(el('button', {
                    type: 'button',
                    class: 'btn btn-outline-secondary',
                    text: this.strings.restore,
                    onclick: () => this.restore(map),
                }));
            }

            return group;
        }

        group.append(el('button', {
            type: 'button',
            class: 'btn btn-outline-secondary',
            text: this.strings.copy,
            onclick: () => this.create({ copyOf: map.id, name: `${map.name} (${this.strings.copy})` }),
        }));

        // Delete is for owners only, and the server refuses it regardless of
        // what this renders — the row carries `role` so the button can simply
        // be absent rather than present and refused.
        if (map.role === 'owner' && !isCurrent) {
            group.append(el('button', {
                type: 'button',
                class: 'btn btn-outline-danger',
                text: this.strings.delete,
                onclick: () => this.remove(map),
            }));
        }

        return group;
    }

    creator() {
        const input = el('input', {
            type: 'text',
            class: 'form-control',
            placeholder: this.strings.newMapName,
            'aria-label': this.strings.newMapName,
        });

        return el('div', { class: 'form-row w-100 align-items-center' }, [
            el('div', { class: 'col' }, [input]),
            el('div', { class: 'col-auto' }, [
                el('button', {
                    type: 'button',
                    class: 'btn btn-primary',
                    text: this.strings.createMap,
                    onclick: () => {
                        const name = input.value.trim();

                        if (name !== '') {
                            this.create({ name });
                        }
                    },
                }),
            ]),
        ]);
    }

    async create({ name, copyOf = null }) {
        try {
            const body = await postJson(`${this.apiBase}/maps`, {
                name,
                clientId: this.clientId,
                seq: this.seq.next(),
                ...(copyOf === null ? {} : { from: { kind: 'copy', mapId: copyOf } }),
            });

            // The creation response has the same shape as the bootstrap read,
            // so the new map opens from it with no follow-up GET.
            this.modal.hide();
            this.onLoad(body.id, body);
        } catch (error) {
            this.showError(error);
        }
    }

    async remove(map) {
        if (!window.confirm(this.strings.confirmDelete.replace(':name', map.name))) {
            return;
        }

        try {
            await deleteJson(`${this.apiBase}/maps/${map.id}`, { openMapId: this.currentMapId() || 0 });

            await this.refresh();
        } catch (error) {
            this.showError(error);
        }
    }

    async restore(map) {
        if (this.onRestore === null) {
            return;
        }

        try {
            await this.onRestore(map.id);
            await this.refresh();
        } catch (error) {
            this.showError(error);
        }
    }

    showError(error) {
        this.state.error = error.problem?.detail || error.message;
        this.render();
    }

    load(map) {
        this.modal.hide();
        this.onLoad(map.id, null);
    }

}
