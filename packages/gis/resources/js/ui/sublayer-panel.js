/**
 * Choosing what a layer is split by.
 *
 * Two steps, and the second is why this is a panel rather than a submenu: the
 * attributes come from the layer's schema and are known instantly, but the
 * VALUES have to be counted out of the features. On the land-use layer that is
 * a scan of 2.2 million rows and measured at 1.8 seconds, so there has to be
 * somewhere to say so.
 *
 * The field the server refuses is the interesting case. A unique key such as
 * `upi` has 672,132 values, and the endpoint answers in no time at all because
 * it stops at the 257th — but there is nothing to offer, and saying "too many
 * values to split by" is the whole of the useful answer.
 */

import { el, clear } from '../lib/dom.js';
import { getJson } from '../lib/http.js';
import { defaultClassification } from '../map/style/classify.js';

export class SublayerPanel {
    /**
     * @param {Object} options
     * @param {Object} options.strings translated labels, from the bootstrap
     * @param {Function} options.onApply called with (row, classification|null)
     */
    constructor({ apiBase, strings, onApply }) {
        this.apiBase = apiBase;
        this.strings = strings;
        this.onApply = onApply;
        this.element = null;
        this.row = null;
    }

    /** Open over the tree, for one layer. */
    open(row) {
        this.close();
        this.row = row;

        const fields = (row.layer.attrSchema ?? [])
            .filter((entry) => entry && typeof entry.name === 'string')
            .map((entry) => entry.name);

        const body = el('div', { class: 'gis-sublayer-body' });

        this.element = el('div', { class: 'gis-sublayer', role: 'dialog', 'aria-modal': 'false' }, [
            el('div', { class: 'gis-sublayer-head' }, [
                el('strong', { text: `${this.strings.split}: ${row.layer.name}` }),
                el('button', {
                    type: 'button',
                    class: 'gis-sublayer-close',
                    'aria-label': this.strings.close,
                    onclick: () => this.close(),
                }, [el('i', { class: 'la la-times', 'aria-hidden': 'true' })]),
            ]),
            body,
        ]);

        if (fields.length === 0) {
            body.append(el('p', { class: 'gis-sublayer-note', text: this.strings.noAttributes }));
        } else {
            body.append(this.fieldList(fields));
        }

        if (row.placement.classification) {
            body.append(el('button', {
                type: 'button',
                class: 'btn btn-sm btn-link gis-sublayer-clear',
                text: this.strings.stopSplitting,
                onclick: () => {
                    this.onApply(this.row, null);
                    this.close();
                },
            }));
        }

        document.body.append(this.element);
    }

    fieldList(fields) {
        const list = el('div', { class: 'gis-sublayer-fields' });
        const current = this.row.placement.classification?.field ?? null;

        for (const field of fields) {
            const button = el('button', {
                type: 'button',
                class: `gis-sublayer-field${field === current ? ' is-current' : ''}`,
                text: field,
                onclick: () => this.chose(field, button),
            });

            list.append(button);
        }

        return list;
    }

    /**
     * Ask the server what values the field has, then split by them.
     *
     * The busy state is not decoration. Nothing visibly happens for up to two
     * seconds, and a button that looks unpressed invites a second press — which
     * would be a second scan of the same two million rows.
     */
    async chose(field, button) {
        if (this.busy) {
            return;
        }

        this.busy = true;
        button.classList.add('is-busy');
        button.disabled = true;

        try {
            const answer = await getJson(
                `${this.apiBase}/layers/${this.row.layer.id}/values?field=${encodeURIComponent(field)}`,
            );

            if (answer.truncated || (answer.values ?? []).length === 0) {
                this.note(answer.truncated
                    ? this.strings.tooManyValues.replace(':max', String(answer.max ?? 256))
                    : this.strings.noValues);

                return;
            }

            const classification = defaultClassification(field, answer.values);

            // The bucket for values no class names. Labelled here rather than
            // in `classify.js`, which has no strings and should not grow a
            // dependency on them for one word.
            classification.other.label = this.strings.otherClass;

            this.onApply(this.row, classification);
            this.close();
        } catch (error) {
            console.error('gis: could not read attribute values', error);
            this.note(this.strings.valuesFailed);
        } finally {
            this.busy = false;
            button.classList.remove('is-busy');
            button.disabled = false;
        }
    }

    note(text) {
        const body = this.element?.querySelector('.gis-sublayer-body');

        if (!body) {
            return;
        }

        body.querySelector('.gis-sublayer-note')?.remove();
        body.append(el('p', { class: 'gis-sublayer-note', text }));
    }

    close() {
        this.element?.remove();
        this.element = null;
        this.row = null;
        this.busy = false;
    }
}
