/**
 * What the colours on the map mean.
 *
 * Generated from the resolved style of every visible layer rather than
 * maintained beside it, which is the only way it cannot drift: a legend a user
 * edits is a legend that eventually disagrees with the map, and the
 * disagreement is invisible until someone acts on it (specification §10).
 *
 * It reads the same `classification` document the sublayer rows do, so a
 * category recoloured in the tree is recoloured here in the same frame,
 * without either side telling the other.
 *
 * **Hidden things are omitted, not greyed.** A legend is a key to what is on
 * the map; an entry for something that is not there is an entry the reader has
 * to work out is irrelevant.
 */

import { el, clear } from '../lib/dom.js';
import { buildIndex, childrenOf, effectiveVisible, isClassified, classRowsOf } from './tree-model.js';
import { classStyle } from '../map/style/classify.js';

/** The four corners it can be pinned to. */
export const CORNERS = ['bottom-right', 'bottom-left', 'top-right', 'top-left'];


/**
 * Everything currently drawn, in tree order, as legend entries.
 *
 * **Pure, and separate from the panel for the reason `tree-model.js` is
 * separate from `layer-tree.js`:** this is the part that fails silently. A
 * legend that lists a hidden category, or omits a visible one, looks like a
 * legend — and only tells you it is wrong when somebody acts on it. The view
 * below holds none of this.
 *
 * Tree order and group nesting are honoured because the legend is read against
 * the map, and a key ordered differently from the thing it describes is a key
 * you have to search.
 */
export function legendEntries(state, zoom) {
    if (!state) {
        return [];
    }

    const index = buildIndex(state);
    const out = [];

        const walk = (parentId, depth) => {
            for (const placement of childrenOf(state, parentId, index)) {
                const layer = state.layers[placement.layerId];

                if (!layer || !effectiveVisible(state, placement, zoom)) {
                    continue;
                }

                if (layer.kind === 'group') {
                    // A group is a heading only if something under it survives
                    // the same test, or the legend grows empty headings.
                    const before = out.length;

                    walk(placement.id, depth + 1);

                    if (out.length > before) {
                        out.splice(before, 0, { kind: 'group', name: layer.name, depth });
                    }

                    continue;
                }

                if (layer.kind !== 'vector') {
                    // A tile or an image has no symbology to explain. Naming it
                    // with a blank swatch would say nothing.
                    continue;
                }

                if (isClassified(placement)) {
                    out.push({ kind: 'layer', name: layer.name, depth, swatch: null });

                    for (const entry of classRowsOf(placement)) {
                        if (entry.visible === false) {
                            continue;
                        }

                        const style = classStyle(entry);

                        out.push({
                            kind: 'class',
                            name: entry.label || entry.value,
                            depth: depth + 1,
                            swatch: {
                                fill: style.fill ?? layer.style?.fill ?? 'transparent',
                                stroke: style.stroke ?? layer.style?.stroke ?? 'transparent',
                            },
                        });
                    }

                    continue;
                }

                out.push({
                    kind: 'layer',
                    name: layer.name,
                    depth,
                    swatch: {
                        fill: layer.style?.fill ?? 'transparent',
                        stroke: layer.style?.stroke ?? 'transparent',
                    },
                });
            }
        };

    walk(null, 0);

    return out;
}

export class Legend {
    /**
     * @param {Object} options
     * @param {HTMLElement} options.container the map shell
     * @param {Object} options.strings translated labels
     * @param {Function} options.zoom () => the map's current zoom
     * @param {Function} options.onChanged called when the user moves or folds it
     */
    constructor({ container, strings, zoom, onChanged = null }) {
        this.strings = strings;
        this.zoom = zoom;
        this.onChanged = onChanged;
        this.store = null;
        this.collapsed = false;
        this.corner = 'bottom-right';

        this.body = el('div', { class: 'gis-legend-body' });

        this.toggle = el('button', {
            type: 'button',
            class: 'gis-legend-toggle',
            'aria-expanded': 'true',
            onclick: () => this.setCollapsed(!this.collapsed),
        }, [
            el('span', { class: 'gis-legend-title', text: strings.legend }),
            el('i', { class: 'la la-angle-down', 'aria-hidden': 'true' }),
        ]);

        this.element = el('div', {
            class: 'gis-legend is-bottom-right',
            role: 'complementary',
            'aria-label': strings.legend,
            hidden: true,
        }, [this.toggle, this.body]);

        container.append(this.element);
    }

    attach(store) {
        this.store = store;

        // The same topics the tree listens to. A legend that refreshed on a
        // timer, or only on a reload, is the drifting legend this avoids.
        // `subscribe` takes one topic, so each is registered separately.
        for (const topic of ['layers', 'tree', 'style']) {
            store.subscribe(topic, () => this.render());
        }
        this.render();
    }

    setCollapsed(collapsed, { save = true } = {}) {
        this.collapsed = collapsed;
        this.element.classList.toggle('is-collapsed', collapsed);
        this.toggle.setAttribute('aria-expanded', String(!collapsed));
        this.body.hidden = collapsed;

        if (save) {
            this.onChanged?.({ collapsed, corner: this.corner });
        }
    }

    setCorner(corner, { save = true } = {}) {
        if (!CORNERS.includes(corner)) {
            return;
        }

        CORNERS.forEach((name) => this.element.classList.toggle(`is-${name}`, name === corner));
        this.corner = corner;

        if (save) {
            this.onChanged?.({ collapsed: this.collapsed, corner });
        }
    }

    /** Everything currently drawn, in tree order. */
    entries() {
        return this.store ? legendEntries(this.store.state, this.zoom()) : [];
    }

    render() {
        const entries = this.entries();

        // Nothing drawn, nothing to explain. Hidden rather than empty: an empty
        // panel is a thing the user has to look at to discover is empty.
        this.element.hidden = entries.length === 0;

        if (entries.length === 0) {
            return;
        }

        clear(this.body);

        for (const entry of entries) {
            const row = el('div', {
                class: `gis-legend-row is-${entry.kind}`,
                style: `--gis-depth: ${entry.depth}`,
            }, [
                entry.swatch
                    ? el('span', {
                        class: 'gis-legend-swatch',
                        'aria-hidden': 'true',
                        style: `background:${entry.swatch.fill};border-color:${entry.swatch.stroke}`,
                    })
                    : null,
                el('span', { class: 'gis-legend-label', text: entry.name }),
            ]);

            this.body.append(row);
        }
    }
}
