/**
 * The drawing toolbar, the live readout, and numeric entry.
 *
 * The toolbar sits over the canvas and holds only things that act on the
 * canvas. S5b moved everything else out of it — choosing a map went beside the
 * map's name, the sidebar toggle beside the way out, isolate with the tree's
 * actions — precisely so that this session could put the tools there and have
 * the position mean something.
 *
 * **Numeric entry is not a secondary path.** A parcel boundary is described by
 * bearings and distances in a title document, and typing those in has to
 * produce the same geometry as tracing them would. The panel below writes
 * through the same `shapes.js` functions the pointer does.
 */

import { el, clear } from '../lib/dom.js';
import { TOOLS } from '../map/draw/draw-session.js';
import { destination } from '../lib/measure.js';
import { rectangleRing, circleRing } from '../map/draw/shapes.js';
import { available } from '../map/ops/routing.js';
import { formatDistance, formatArea, formatBearing } from '../lib/units.js';

/** Line Awesome icons, one per operation on a selected feature. */
const OP_ICONS = {
    buffer: 'la-dot-circle',
    simplify: 'la-compress-arrows-alt',
    convexHull: 'la-expand',
    makeValid: 'la-wrench',
};

/** Line Awesome icons, one per tool. */
const ICONS = {
    point: 'la-map-marker',
    line: 'la-slash',
    polygon: 'la-draw-polygon',
    rectangle: 'la-vector-square',
    circle: 'la-circle',
    freehand: 'la-pencil-alt',
};

export class Toolbar {
    /**
     * @param {Object} options
     * @param {HTMLElement} options.container the `.gis-toolbar` element
     * @param {Object} options.strings translated labels
     * @param {Function} options.onTool called with a tool name, or null
     * @param {Function} options.onNumeric called with a finished GeoJSON geometry
     * @param {Function} options.centre () => the map centre as [lng, lat]
     */
    constructor({ container, strings, onTool, onNumeric, centre, onOperation = null }) {
        this.container = container;
        this.strings = strings;
        this.onTool = onTool;
        this.onNumeric = onNumeric;
        this.onOperation = onOperation;
        this.centre = centre;
        this.tool = null;
        this.buttons = new Map();

        this.readout = el('div', { class: 'gis-readout', hidden: true });
        this.numeric = el('div', { class: 'gis-numeric', hidden: true });

        container.hidden = false;
        clear(container);

        for (const tool of TOOLS) {
            const button = el('button', {
                type: 'button',
                class: 'btn btn-light',
                title: strings[tool] ?? tool,
                'aria-label': strings[tool] ?? tool,
                'aria-pressed': 'false',
                onclick: () => this.select(tool),
            }, [el('i', { class: `la ${ICONS[tool]}`, 'aria-hidden': 'true' })]);

            this.buttons.set(tool, button);
            container.append(button);
        }

        // Into the MAP, not the toolbar's parent. `#gis-app` spans the
        // sidebar too, so a panel positioned against it starts underneath the
        // layer tree — which is exactly where the first three fields of the
        // numeric panel went.
        const shell = document.getElementById('gis-map') ?? container.parentElement;

        shell?.append(this.readout, this.numeric);
    }

    /**
     * Show or hide the operations that act on a selected feature.
     *
     * They appear only when there is something to act on, and only the ones
     * this deployment can actually complete — `available()` refuses buffer and
     * make-valid where GEOS is absent, because a control that cannot finish is
     * worse than one that is not there.
     */
    setOperationsFor(target, capabilities) {
        this.opsGroup?.remove();
        this.opsGroup = null;

        if (!target) {
            return;
        }

        const ops = ['buffer', 'simplify', 'convexHull', 'makeValid']
            .filter((op) => available(op, capabilities));

        if (ops.length === 0) {
            return;
        }

        this.opsGroup = el('div', { class: 'btn-group btn-group-sm gis-ops' }, ops.map((op) => el('button', {
            type: 'button',
            class: 'btn btn-light',
            title: this.strings[op] ?? op,
            'aria-label': this.strings[op] ?? op,
            onclick: () => this.onOperation?.(op),
        }, [el('i', { class: `la ${OP_ICONS[op]}`, 'aria-hidden': 'true' })])));

        this.container.after(this.opsGroup);
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

        this.numeric.hidden = tool === null;

        if (tool !== null) {
            this.renderNumeric(tool);
        } else {
            this.showReadout(null);
        }
    }

    /**
     * The live figures while drawing.
     *
     * `aria-live="polite"` rather than `assertive`: these change on every
     * pointer move, and an assertive region would interrupt a screen reader
     * continuously for the whole of a drawing gesture.
     */
    showReadout(readout) {
        if (!readout) {
            this.readout.hidden = true;
            clear(this.readout);

            return;
        }

        const rows = [];

        if (readout.bearing !== null && readout.segment > 0) {
            rows.push([this.strings.segment, `${metres(readout.segment)}  ${degrees(readout.bearing)}`]);
        }

        if (readout.total > 0) {
            rows.push([this.strings.totalLength, metres(readout.total)]);
        }

        if (readout.radius !== undefined) {
            rows.push([this.strings.radius, metres(readout.radius)]);
        }

        if (readout.area) {
            rows.push([this.strings.area, squareMetres(readout.area)]);
        }

        clear(this.readout);
        this.readout.setAttribute('aria-live', 'polite');
        this.readout.hidden = rows.length === 0;

        for (const [label, value] of rows) {
            this.readout.append(el('div', { class: 'gis-readout-row' }, [
                el('span', { class: 'gis-readout-label', text: label }),
                el('span', { class: 'gis-readout-value', text: value }),
            ]));
        }
    }

    /**
     * The fields for the active tool.
     *
     * One shape per tool rather than a generic form: "width, height, rotation"
     * and "centre, radius" are different questions, and a form that asked both
     * would be asking the user to work out which half to ignore.
     */
    renderNumeric(tool) {
        clear(this.numeric);

        const [lng, lat] = this.centre();
        const fields = {
            point: [['lng', this.strings.longitude, lng], ['lat', this.strings.latitude, lat]],
            line: [
                ['lng', this.strings.longitude, lng], ['lat', this.strings.latitude, lat],
                ['bearing', this.strings.bearing, 0], ['distance', this.strings.distance, 100],
            ],
            polygon: [
                ['lng', this.strings.longitude, lng], ['lat', this.strings.latitude, lat],
                ['bearing', this.strings.bearing, 0], ['distance', this.strings.distance, 100],
            ],
            rectangle: [
                ['lng', this.strings.longitude, lng], ['lat', this.strings.latitude, lat],
                ['width', this.strings.width, 100], ['height', this.strings.height, 50],
                ['rotation', this.strings.rotation, 90],
            ],
            circle: [
                ['lng', this.strings.longitude, lng], ['lat', this.strings.latitude, lat],
                ['radius', this.strings.radius, 100],
            ],
            freehand: [],
        }[tool] ?? [];

        if (fields.length === 0) {
            this.numeric.hidden = true;

            return;
        }

        const inputs = new Map();

        for (const [name, label, value] of fields) {
            const input = el('input', {
                type: 'number',
                step: 'any',
                class: 'form-control form-control-sm',
                value: String(Number(value).toFixed(name === 'lng' || name === 'lat' ? 6 : 2)),
                'aria-label': label,
            });

            inputs.set(name, input);
            this.numeric.append(el('label', { class: 'gis-numeric-field' }, [
                el('span', { text: label }),
                input,
            ]));
        }

        this.numeric.append(el('button', {
            type: 'button',
            class: 'btn btn-sm btn-primary',
            text: this.strings.place,
            onclick: () => this.place(tool, inputs),
        }));
    }

    /** Build the geometry the typed numbers describe. */
    place(tool, inputs) {
        const value = (name) => Number(inputs.get(name)?.value ?? 0);
        const origin = [value('lng'), value('lat')];

        if (![origin[0], origin[1]].every(Number.isFinite)) {
            return;
        }

        const geometry = {
            point: () => ({ type: 'Point', coordinates: origin }),
            // One segment, which the user extends by placing the next from its
            // end. A whole traverse in one box would be a text format nobody
            // asked for.
            line: () => ({
                type: 'LineString',
                coordinates: [origin, destination(origin, value('bearing'), value('distance'))],
            }),
            polygon: () => ({
                type: 'LineString',
                coordinates: [origin, destination(origin, value('bearing'), value('distance'))],
            }),
            rectangle: () => ({
                type: 'Polygon',
                coordinates: [rectangleRing(origin, value('width'), value('height'), value('rotation'))],
            }),
            circle: () => ({
                type: 'Polygon',
                coordinates: [circleRing(origin, value('radius'))],
            }),
        }[tool];

        const result = geometry?.();

        if (result) {
            this.onNumeric?.(result);
        }
    }
}

/*
 * The readout's formatting, which used to live here.
 *
 * It carried its own 1000, 10,000 and 1,000,000 and its own DMS arithmetic —
 * a second set of conversion factors in a component, which §11 forbids in as
 * many words, and which had already drifted: this file rounded a kilometre to
 * three decimals where `units.js` rounds to three and a hectare to four where
 * it rounds to four, but neither knew about the other, and a change to one
 * would have moved only half the numbers a user sees. It delegates now, and
 * `units.js` is the only place in this package where a factor exists.
 *
 * The preferences are not threaded through yet: the readout is live figures
 * during a gesture rather than a saved value, and S10b's preference plumbing
 * stops at the annotation. That is a gap, not a design — it means a user
 * working in acres still draws in hectares.
 */
const metres = formatDistance;
const squareMetres = formatArea;
const degrees = formatBearing;
