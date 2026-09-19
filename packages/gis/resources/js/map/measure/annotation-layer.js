/**
 * Saved measurements, painted on the overlay canvas.
 *
 * Between the features and the work in progress, on the canvas that S2 created
 * and nothing had yet drawn to. That placement is the point: a measurement
 * survives a pan, so it cannot live on the edit canvas, which is cleared on
 * every gesture; and it is not a feature, so it cannot live on the feature
 * canvas, whose paths are cached per layer and rebuilt per zoom.
 *
 * Visibility is one flag for the whole map, not one per measurement. The
 * specification asks for that explicitly, and the reason holds up: a
 * measurement list is a working scratchpad, and per-row visibility is a second
 * kind of hidden — a row you cannot see and a row that is not there look the
 * same on the map and different in the list.
 */

import { describe, anchorOf, outlineOf } from './annotations.js';

/** Enough contrast to read over a satellite tile and over paper-white alike. */
const STROKE = '#b7791f';
const SELECTED = '#c05621';

export class AnnotationLayer {
    /**
     * @param {Object} options
     * @param {Object} options.map the Leaflet map
     * @param {Object} options.renderer owns the overlay canvas
     */
    constructor({ map, renderer }) {
        this.map = map;
        this.renderer = renderer;

        this.items = [];
        this.visible = true;
        this.selectedId = null;
        this.preferences = {};

        renderer.setOverlayPainter('measurements', (context) => this.paint(context));
    }

    /** @param {Array<Object>} items measurements, geometry and all */
    setItems(items) {
        this.items = items ?? [];
        this.renderer.schedule();
    }

    setVisible(visible) {
        this.visible = visible;
        this.renderer.schedule();
    }

    setSelected(id) {
        this.selectedId = id;
        this.renderer.schedule();
    }

    setPreferences(preferences) {
        this.preferences = preferences ?? {};
        this.renderer.schedule();
    }

    paint(context) {
        if (!this.visible || this.items.length === 0) {
            return;
        }

        context.save();
        context.lineJoin = 'round';
        context.font = '12px system-ui, sans-serif';
        context.textAlign = 'center';
        context.textBaseline = 'middle';

        for (const measurement of this.items) {
            this.paintOne(context, measurement);
        }

        context.restore();
    }

    paintOne(context, measurement) {
        const outline = outlineOf(measurement.geom);

        if (outline.length < 2) {
            return;
        }

        const selected = measurement.id === this.selectedId;
        const screen = outline.map(([lng, lat]) => this.map.latLngToContainerPoint([lat, lng]));

        // A white halo under the line, for the same reason the label has one:
        // an amber line over an amber roof is not a line.
        context.beginPath();
        screen.forEach((point, index) => (index === 0
            ? context.moveTo(point.x, point.y)
            : context.lineTo(point.x, point.y)));
        context.strokeStyle = 'rgba(255, 255, 255, 0.85)';
        context.lineWidth = selected ? 7 : 5;
        context.stroke();
        context.strokeStyle = selected ? SELECTED : STROKE;
        context.lineWidth = selected ? 3 : 2;
        context.stroke();

        for (const point of screen) {
            context.beginPath();
            context.arc(point.x, point.y, 3, 0, Math.PI * 2);
            context.fillStyle = '#ffffff';
            context.fill();
            context.strokeStyle = selected ? SELECTED : STROKE;
            context.lineWidth = 2;
            context.stroke();
        }

        const anchor = anchorOf(measurement.geom);

        if (!anchor) {
            return;
        }

        const at = this.map.latLngToContainerPoint([anchor[1], anchor[0]]);
        const text = describe(measurement, this.preferences);

        // Clear of the line, not on it. A line's anchor is its midpoint, so
        // the default puts the text exactly where the line is — the halo keeps
        // it readable but the stroke still runs through the digits. Offset
        // PERPENDICULAR to the line rather than simply upwards, or a vertical
        // measurement is back where it started.
        const offset = measurement.geom?.type === 'Polygon'
            ? { x: 0, y: 0 }
            : perpendicular(screen[0], screen[screen.length - 1], 12);

        // Stroked then filled, rather than drawn on a box. A box large enough
        // for "1,234.56 m²" hides whatever it was measuring.
        context.lineWidth = 3;
        context.strokeStyle = 'rgba(255, 255, 255, 0.9)';
        context.strokeText(text, at.x + offset.x, at.y + offset.y);
        context.fillStyle = selected ? SELECTED : '#744210';
        context.fillText(text, at.x + offset.x, at.y + offset.y);
    }
}

/**
 * A screen-space offset at right angles to a segment.
 *
 * Always to one side of the line rather than always above it, and it falls
 * back to straight up when the two points coincide — which happens mid-gesture,
 * before the second vertex has moved anywhere.
 */
function perpendicular(from, to, by) {
    const dx = to.x - from.x;
    const dy = to.y - from.y;
    const span = Math.hypot(dx, dy);

    if (span === 0) {
        return { x: 0, y: -by };
    }

    // Normal, then flipped so it always points to the upper side: a label
    // below its line reads as belonging to whatever is beneath it.
    const nx = -dy / span;
    const ny = dx / span;

    return ny > 0 ? { x: -nx * by, y: -ny * by } : { x: nx * by, y: ny * by };
}
