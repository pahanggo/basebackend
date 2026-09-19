/**
 * The measurement tools and the list of what they produced.
 *
 * **Measurements are not in the layer tree, and this panel is why.** The tree
 * is built from `gis_map_layer` rows; a measurement has no placement, no
 * layer and nothing to point at, so putting one there means a fabricated row
 * that every tree query, every drag rule and every reorder has to special-case
 * — for a list that is a scratchpad rather than a stack (specification §11).
 *
 * The tools live here rather than in the drawing toolbar for the same reason
 * they are a separate session: drawing writes a feature into a layer and
 * measuring writes an annotation onto the map. They look identical in the hand
 * and are completely different in their consequences, and putting them in one
 * strip of buttons invites exactly the mistake — measuring a boundary and
 * finding you have edited it.
 */

import { el, clear } from '../lib/dom.js';
import { MEASURE_TOOLS, formatValue } from '../map/measure/annotations.js';

/** Line Awesome icons, one per tool. */
const ICONS = {
    distance: 'la-ruler',
    area: 'la-vector-square',
    radius: 'la-dot-circle',
    diameter: 'la-arrows-alt-h',
    bearing: 'la-compass',
    info: 'la-info-circle',
};

export class MeasurePanel {
    /**
     * @param {Object} options
     * @param {HTMLElement} options.container
     * @param {Object} options.strings
     * @param {Function} options.onTool (tool|null) => void
     * @param {Function} options.onSelect (id|null) => void
     * @param {Function} options.onRename (measurement, label) => void
     * @param {Function} options.onDelete (measurement) => void
     * @param {Function} options.onZoomTo (measurement) => void
     * @param {Function} options.onVisible (boolean) => void
     */
    constructor({ container, strings, onTool, onSelect, onRename, onDelete, onZoomTo, onVisible }) {
        this.strings = strings;
        this.onTool = onTool;
        this.onSelect = onSelect;
        this.onRename = onRename;
        this.onDelete = onDelete;
        this.onZoomTo = onZoomTo;
        this.onVisible = onVisible;

        this.tool = null;
        this.items = [];
        this.selectedId = null;
        this.preferences = {};
        this.buttons = new Map();
        this.readOnly = false;

        this.tools = el('div', { class: 'gis-measure-tools btn-group btn-group-sm', role: 'group' });

        for (const tool of MEASURE_TOOLS) {
            const button = el('button', {
                type: 'button',
                class: 'btn btn-light',
                title: strings[`measure_${tool}`] ?? tool,
                'aria-label': strings[`measure_${tool}`] ?? tool,
                'aria-pressed': 'false',
                onclick: () => this.select(tool),
            }, [el('i', { class: `la ${ICONS[tool]}`, 'aria-hidden': 'true' })]);

            this.buttons.set(tool, button);
            this.tools.append(button);
        }

        this.visibility = el('input', {
            type: 'checkbox',
            class: 'gis-measure-visible',
            checked: true,
            id: 'gis-measure-visible',
            'aria-label': strings.measure_show ?? 'Show measurements',
            onchange: (event) => this.onVisible?.(event.target.checked),
        });

        this.list = el('ul', { class: 'gis-measure-list', role: 'list' });
        this.empty = el('p', { class: 'gis-measure-empty', text: strings.measure_empty ?? '' });

        this.bodyNode = el('div', { class: 'gis-panel-body', id: 'gis-measure-body' }, [
            this.tools,
            el('label', { class: 'gis-measure-toggle' }, [
                this.visibility,
                el('span', { text: strings.measure_show ?? 'Show measurements' }),
            ]),
            this.list,
            this.empty,
        ]);

        this.caret = el('i', { class: 'la la-caret-right', 'aria-hidden': 'true' });

        this.toggle = el('button', {
            type: 'button',
            class: 'gis-section-toggle gis-panel-toggle',
            'aria-expanded': 'false',
            'aria-controls': 'gis-measure-body',
            onclick: () => this.setCollapsed(!this.collapsed),
        }, [this.caret, el('span', { text: strings.measure ?? 'Measure' })]);

        this.root = el('div', { class: 'gis-panel gis-measure' }, [this.toggle, this.bodyNode]);

        container.append(this.root);

        // Collapsed to begin with. It is a tool someone reaches for, not a
        // thing they read, and the sidebar below it is the layer tree.
        this.collapsed = false;
        this.setCollapsed(true);
        this.render();
    }

    setCollapsed(collapsed) {
        this.collapsed = collapsed;
        this.bodyNode.hidden = collapsed;
        this.toggle.setAttribute('aria-expanded', String(!collapsed));
        this.caret.className = `la la-caret-${collapsed ? 'right' : 'down'}`;

        // Collapsing the panel puts the tool away with it. Leaving a crosshair
        // armed behind a closed panel is how a click becomes a measurement
        // nobody meant to take.
        if (collapsed && this.tool) {
            this.setTool(null);
            this.onTool?.(null);
        }
    }

    /** Choosing the active tool again turns it off, which is how Escape feels. */
    select(tool) {
        this.setTool(this.tool === tool ? null : tool);
        this.onTool?.(this.tool);
    }

    setTool(tool) {
        this.tool = tool;

        for (const [name, button] of this.buttons) {
            const active = name === tool;

            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', String(active));
        }
    }

    /** A viewer may measure on screen but may not save; the list is read-only. */
    setReadOnly(readOnly) {
        this.readOnly = readOnly;
        this.render();
    }

    setPreferences(preferences) {
        this.preferences = preferences ?? {};
        this.render();
    }

    setItems(items) {
        this.items = items ?? [];
        this.render();
    }

    setSelected(id) {
        this.selectedId = id;

        for (const row of this.list.querySelectorAll('.gis-measure-row')) {
            row.classList.toggle('is-selected', String(row.dataset.id) === String(id));
        }
    }

    render() {
        clear(this.list);

        this.empty.hidden = this.items.length > 0;

        for (const measurement of this.items) {
            this.list.append(this.row(measurement));
        }

        this.setSelected(this.selectedId);
    }

    /**
     * One saved measurement.
     *
     * The number is shown beside the name rather than instead of it, unlike
     * the label on the map: here there is room, and the number is what the row
     * is for — a list of six names and no figures is a list of nothing.
     */
    row(measurement) {
        const name = el('button', {
            type: 'button',
            class: 'gis-measure-name',
            text: measurement.label || (this.strings[`measure_${measurement.kind}`] ?? measurement.kind),
            onclick: () => this.onSelect?.(measurement.id),
            ondblclick: () => this.rename(measurement),
        });

        const actions = [
            el('button', {
                type: 'button',
                class: 'btn btn-sm btn-link',
                title: this.strings.zoomToFeature ?? 'Zoom to',
                'aria-label': this.strings.zoomToFeature ?? 'Zoom to',
                onclick: () => this.onZoomTo?.(measurement),
            }, [el('i', { class: 'la la-search-location', 'aria-hidden': 'true' })]),
        ];

        if (!this.readOnly) {
            actions.push(el('button', {
                type: 'button',
                class: 'btn btn-sm btn-link',
                title: this.strings.rename ?? 'Rename',
                'aria-label': this.strings.rename ?? 'Rename',
                onclick: () => this.rename(measurement),
            }, [el('i', { class: 'la la-pen', 'aria-hidden': 'true' })]));

            actions.push(el('button', {
                type: 'button',
                class: 'btn btn-sm btn-link text-danger',
                title: this.strings.delete ?? 'Delete',
                'aria-label': this.strings.delete ?? 'Delete',
                onclick: () => this.onDelete?.(measurement),
            }, [el('i', { class: 'la la-trash', 'aria-hidden': 'true' })]));
        }

        return el('li', {
            class: 'gis-measure-row',
            dataset: { id: measurement.id },
        }, [
            name,
            el('span', {
                class: 'gis-measure-value',
                text: formatValue(measurement.kind, measurement.value, this.preferences),
            }),
            el('span', { class: 'gis-measure-actions' }, actions),
        ]);
    }

    /**
     * Rename, through the application's own prompt.
     *
     * `swal` rather than `window.prompt`, which Backpack already bundles and
     * which this package uses everywhere else — and a native prompt is a modal
     * browser dialog, which blocks the whole page and cannot be styled.
     */
    rename(measurement) {
        if (this.readOnly) {
            return;
        }

        window.swal({
            title: this.strings.rename ?? 'Rename',
            content: {
                element: 'input',
                attributes: { value: measurement.label ?? '' },
            },
            buttons: [this.strings.cancel ?? 'Cancel', this.strings.save ?? 'Save'],
        }).then((label) => {
            if (label !== null) {
                this.onRename?.(measurement, String(label).trim() || null);
            }
        });
    }
}
