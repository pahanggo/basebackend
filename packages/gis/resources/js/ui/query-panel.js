/**
 * Finding features by where they are and what they say.
 *
 * A section at the foot of the layers sidebar rather than a floating panel
 * over the map: it acts on a layer and produces a selection, and both of those
 * live on the left (specification §8).
 *
 * **This is not Search.** Search is a text box that finds a named thing; this
 * builds a predicate. They read similarly and do different jobs, and merging
 * them would give one control two behaviours nobody can predict.
 *
 * The heavy lifting is the server's. The client sends a relation, a shape and
 * a list of attribute clauses, and gets back ids — the two indexes involved
 * cannot be combined in one plan, and deciding which to lead with is exactly
 * the kind of thing that belongs next to the data.
 */

import { el, clear } from '../lib/dom.js';

/** The relations offered, in the order they are worth reaching for. */
export const RELATIONS = ['intersects', 'within', 'contains', 'crosses', 'touches', 'disjoint'];

/** Operators, by the kind of field they apply to. */
const OPERATORS = {
    string: ['=', '!=', 'contains', 'starts', 'in'],
    number: ['=', '!=', '<', '<=', '>', '>='],
};

export class QueryPanel {
    /**
     * @param {Object} options
     * @param {HTMLElement} options.container the sidebar
     * @param {Object} options.strings translated labels
     * @param {Function} options.onRun called with the request body and layer
     * @param {Function} options.layers () => the vector layers on offer
     */
    constructor({ container, strings, onRun, layers }) {
        this.strings = strings;
        this.onRun = onRun;
        this.layers = layers;
        this.clauses = [];

        this.layerSelect = el('select', { class: 'form-control form-control-sm gis-query-layer' });
        this.layerSelect.addEventListener('change', () => this.renderClauses());

        this.relationSelect = el('select', { class: 'form-control form-control-sm' },
            RELATIONS.map((name) => el('option', { value: name, text: strings[`relation_${name}`] ?? name })));

        this.useShape = el('input', { type: 'checkbox', id: 'gis-query-shape' });
        this.buffer = el('input', {
            type: 'number', step: 'any', value: '0',
            class: 'form-control form-control-sm',
            'aria-label': strings.bufferMetres,
        });

        this.clauseList = el('div', { class: 'gis-query-clauses' });
        this.result = el('p', { class: 'gis-query-result', 'aria-live': 'polite' });

        this.body = el('div', { class: 'gis-panel-section' }, [
            el('label', { class: 'gis-query-field' }, [
                el('span', { text: strings.queryLayer }),
                this.layerSelect,
            ]),
            el('label', { class: 'gis-query-check' }, [
                this.useShape,
                el('span', { text: strings.queryWithinView }),
            ]),
            el('label', { class: 'gis-query-field' }, [
                el('span', { text: strings.relation }),
                this.relationSelect,
            ]),
            el('label', { class: 'gis-query-field' }, [
                el('span', { text: strings.bufferMetres }),
                this.buffer,
            ]),
            this.clauseList,
            el('button', {
                type: 'button',
                class: 'btn btn-sm btn-light gis-query-add',
                text: strings.addCondition,
                onclick: () => this.addClause(),
            }),
            el('button', {
                type: 'button',
                class: 'btn btn-sm btn-primary gis-query-run',
                text: strings.runQuery,
                onclick: () => this.run(),
            }),
            this.result,
        ]);

        this.element = el('section', { class: 'gis-panel gis-query' }, [
            el('button', {
                type: 'button',
                class: 'gis-panel-header',
                'aria-expanded': 'false',
                onclick: () => this.setCollapsed(!this.collapsed),
            }, [
                el('i', { class: 'la la-caret-right', 'aria-hidden': 'true' }),
                el('span', { text: strings.query }),
            ]),
            this.body,
        ]);

        container.append(this.element);
        this.setCollapsed(true);
    }

    setCollapsed(collapsed) {
        this.collapsed = collapsed;
        this.body.hidden = collapsed;
        this.element.querySelector('.gis-panel-header').setAttribute('aria-expanded', String(!collapsed));
        this.element.querySelector('.gis-panel-header .la').className =
            `la ${collapsed ? 'la-caret-right' : 'la-caret-down'}`;

        if (!collapsed) {
            this.refreshLayers();
        }
    }

    /** The vector layers currently in the map, as options. */
    refreshLayers() {
        const layers = this.layers();
        const chosen = this.layerSelect.value;

        clear(this.layerSelect);

        for (const layer of layers) {
            this.layerSelect.append(el('option', { value: String(layer.id), text: layer.name }));
        }

        if (layers.some((layer) => String(layer.id) === chosen)) {
            this.layerSelect.value = chosen;
        }

        this.renderClauses();
    }

    get layer() {
        return this.layers().find((layer) => String(layer.id) === this.layerSelect.value) ?? null;
    }

    addClause() {
        const fields = this.layer?.attrSchema ?? [];

        if (fields.length === 0) {
            return;
        }

        this.clauses.push({ field: fields[0].name, op: '=', value: '' });
        this.renderClauses();
    }

    /**
     * The clause rows.
     *
     * Rebuilt whole on every change rather than patched: there are at most a
     * handful, and a partial update is where a stale operator list for a field
     * that has changed type would come from.
     */
    renderClauses() {
        clear(this.clauseList);

        const fields = this.layer?.attrSchema ?? [];

        // A clause naming a field the chosen layer does not have is a clause
        // the server will refuse, so it is dropped when the layer changes.
        this.clauses = this.clauses.filter((clause) => fields.some((f) => f.name === clause.field));

        this.clauses.forEach((clause, index) => {
            const field = fields.find((f) => f.name === clause.field);
            const operators = OPERATORS[field?.type === 'number' ? 'number' : 'string'];

            if (!operators.includes(clause.op)) {
                clause.op = operators[0];
            }

            const fieldSelect = el('select', { class: 'form-control form-control-sm' },
                fields.map((f) => el('option', { value: f.name, text: f.name, selected: f.name === clause.field })));

            fieldSelect.addEventListener('change', () => {
                clause.field = fieldSelect.value;
                this.renderClauses();
            });

            const opSelect = el('select', { class: 'form-control form-control-sm' },
                operators.map((op) => el('option', { value: op, text: op, selected: op === clause.op })));

            opSelect.addEventListener('change', () => { clause.op = opSelect.value; });

            const value = el('input', {
                type: field?.type === 'number' ? 'number' : 'text',
                step: 'any',
                class: 'form-control form-control-sm',
                value: String(clause.value ?? ''),
                'aria-label': this.strings.value,
            });

            value.addEventListener('input', () => { clause.value = value.value; });

            this.clauseList.append(el('div', { class: 'gis-query-clause' }, [
                fieldSelect,
                opSelect,
                value,
                el('button', {
                    type: 'button',
                    class: 'gis-query-drop',
                    'aria-label': this.strings.remove,
                    onclick: () => {
                        this.clauses.splice(index, 1);
                        this.renderClauses();
                    },
                }, [el('i', { class: 'la la-times', 'aria-hidden': 'true' })]),
            ]));
        });
    }

    /** Hand the request to the editor, which owns the map and the selection. */
    run() {
        const layer = this.layer;

        if (!layer) {
            return;
        }

        this.result.textContent = this.strings.queryRunning;

        this.onRun?.(layer, {
            relation: this.relationSelect.value,
            useViewport: this.useShape.checked,
            bufferMetres: Number(this.buffer.value) || 0,
            where: this.clauses
                .filter((clause) => String(clause.value).trim() !== '')
                .map((clause) => ({
                    field: clause.field,
                    op: clause.op,
                    // `in` takes a list, written the way anyone writes one.
                    value: clause.op === 'in'
                        ? String(clause.value).split(',').map((part) => part.trim()).filter(Boolean)
                        : clause.value,
                })),
        });
    }

    /** Report what came back, including how much was examined to get it. */
    report(outcome) {
        if (outcome.error) {
            this.result.textContent = outcome.error;

            return;
        }

        this.result.textContent = this.strings.queryFound
            .replace(':count', String(outcome.count))
            .replace(':examined', String(outcome.examined ?? outcome.count));
    }
}
