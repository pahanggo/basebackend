/**
 * "Add from library" — place an existing layer in the current map.
 *
 * **Not "Import".** In this specification import means reading a file, which
 * parses and validates untrusted input; this writes a single pivot row. Reusing
 * the word for both would confuse two operations that share nothing
 * (specification section 8).
 *
 * The modal offers the access levels the server will actually grant, and the
 * server re-checks every one of them on `layer.share` regardless. **A disabled
 * control in a modal is not an authorization boundary** — anyone who can open
 * the console can issue the command directly, so the control here is a courtesy
 * and the check there is the fence.
 */

import { el, relativeTime } from '../lib/dom.js';
import { getJson } from '../lib/http.js';
import { Modal } from './modal.js';

const SEARCH_DEBOUNCE_MS = 300;

export class LayerLibrary {
    /**
     * @param {Object} options
     * @param {string} options.apiBase
     * @param {Object} options.strings
     * @param {Function} options.currentMapId
     * @param {Function} options.onPlace called with (layerId, access)
     */
    constructor({ apiBase, strings, currentMapId, onPlace }) {
        this.apiBase = apiBase;
        this.strings = strings;
        this.currentMapId = currentMapId;
        this.onPlace = onPlace;

        this.state = { q: '', kind: '', page: 1, rows: [], meta: null, error: null };
        this.searchTimer = null;

        this.modal = new Modal({ id: 'gis-layer-library', title: strings.addFromLibrary });
    }

    async open() {
        this.modal.show();

        await this.refresh();
    }

    async refresh() {
        try {
            const body = await getJson(`${this.apiBase}/layers`, {
                q: this.state.q,
                kind: this.state.kind,
                page: this.state.page,
                excludePlacedIn: this.currentMapId() || '',
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
    }

    controls() {
        const search = el('input', {
            type: 'search',
            class: 'form-control',
            placeholder: this.strings.searchLayers,
            value: this.state.q,
            'aria-label': this.strings.searchLayers,
            oninput: (event) => {
                window.clearTimeout(this.searchTimer);

                const value = event.target.value;

                this.searchTimer = window.setTimeout(() => {
                    this.state.q = value;
                    this.state.page = 1;
                    this.refresh();
                }, SEARCH_DEBOUNCE_MS);
            },
        });

        const kinds = el('select', {
            class: 'form-control',
            'aria-label': this.strings.kind,
            onchange: (event) => {
                this.state.kind = event.target.value;
                this.state.page = 1;
                this.refresh();
            },
        }, [
            el('option', { value: '', text: this.strings.allKinds }),
            el('option', { value: 'vector', text: this.strings.vector }),
            el('option', { value: 'tile', text: this.strings.tile }),
            el('option', { value: 'wms', text: 'WMS' }),
            el('option', { value: 'image', text: this.strings.image }),
        ]);

        return el('div', { class: 'form-row mb-3' }, [
            el('div', { class: 'col' }, [search]),
            el('div', { class: 'col-auto' }, [kinds]),
        ]);
    }

    table() {
        if (this.state.error) {
            return el('div', { class: 'alert alert-danger', role: 'alert', text: this.state.error });
        }

        if (this.state.rows.length === 0) {
            return el('p', { class: 'text-muted mb-0', text: this.strings.noLayers });
        }

        return el('div', { class: 'table-responsive' }, [
            el('table', { class: 'table table-sm table-hover mb-0' }, [
                el('thead', {}, [
                    el('tr', {}, [
                        el('th', { scope: 'col', text: this.strings.name }),
                        el('th', { scope: 'col', text: this.strings.kind }),
                        el('th', { scope: 'col', text: this.strings.owner }),
                        el('th', { scope: 'col', class: 'text-right', text: this.strings.features }),
                        el('th', { scope: 'col', text: this.strings.updated }),
                        el('th', { scope: 'col', class: 'text-right', text: this.strings.access }),
                    ]),
                ]),
                el('tbody', {}, this.state.rows.map((row) => this.row(row))),
            ]),
        ]);
    }

    row(layer) {
        // Already placed: listed and marked rather than hidden, so a user
        // searching for something they added yesterday sees why it is not
        // selectable instead of concluding it is gone.
        const placed = layer.placedInThisMap;

        return el('tr', { class: placed ? 'text-muted' : '' }, [
            el('td', {}, [
                el('span', { text: layer.name }),
                placed ? ' ' : null,
                placed ? el('span', { class: 'badge badge-light', text: this.strings.alreadyAdded }) : null,
            ]),
            el('td', { text: layer.kind }),

            // A global layer has no owning map. Calling it "Base data" says
            // what it is rather than leaving the column blank.
            el('td', { text: layer.ownerMapName || this.strings.baseData }),

            // The weight of what is being added, visible before it is added.
            el('td', { class: 'text-right', text: layer.featureCount.toLocaleString() }),
            el('td', { title: layer.updatedAt || '', text: relativeTime(layer.updatedAt) }),
            el('td', { class: 'text-right' }, [this.accessButtons(layer, placed)]),
        ]);
    }

    accessButtons(layer, placed) {
        const group = el('div', { class: 'btn-group btn-group-sm' });

        if (placed) {
            return group;
        }

        for (const access of layer.availableAccess) {
            group.append(el('button', {
                type: 'button',
                class: access === 'edit' ? 'btn btn-outline-primary' : 'btn btn-outline-secondary',
                text: access === 'edit' ? this.strings.addEditable : this.strings.addReadOnly,
                onclick: async () => {
                    try {
                        await this.onPlace(layer.id, access);
                        await this.refresh();
                    } catch (error) {
                        this.state.error = error.problem?.detail || error.message;
                        this.render();
                    }
                },
            }));
        }

        return group;
    }
}
