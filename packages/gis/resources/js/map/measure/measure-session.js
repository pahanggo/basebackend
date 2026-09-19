/**
 * Taking a measurement with the pointer.
 *
 * Close kin to `DrawSession`, and deliberately not the same object. Drawing
 * produces a FEATURE, which belongs to a layer, obeys a layer lock, goes
 * through geometry validation and lands on the feature canvas. A measurement
 * belongs to the map, is authorised by the map role alone, is never validated
 * as a parcel would be, and lands on the overlay canvas. The two share a
 * gesture and almost nothing else; folding them together would mean a
 * conditional at every one of those points, which is how the shorter file
 * becomes the harder one.
 *
 * What they DO share is the edit canvas and its single painter slot, so the
 * caller must make a measure tool and a draw tool mutually exclusive — both
 * would otherwise install a painter and the second would win silently.
 */

import { constrainToAngle } from '../draw/shapes.js';
import { distance, bearing, length, ringArea } from '../../lib/measure.js';
import { geometryFor, valueFor, TOOL_KIND, TOOL_POINTS } from './annotations.js';

/** Tools that finish on their own once they have what they need. */
const FIXED = new Set(['radius', 'diameter', 'bearing']);

export class MeasureSession {
    /**
     * @param {Object} options
     * @param {Object} options.map the Leaflet map
     * @param {Object} options.renderer supplies the edit canvas
     * @param {Function} options.onCommit ({kind, tool, geom, value}) => void
     * @param {Function} options.onReadout called with the live figures, or null
     * @param {Function} options.onInfo called with a container point, for the info tool
     */
    constructor({ map, renderer, onCommit, onReadout, onInfo = null }) {
        this.map = map;
        this.renderer = renderer;
        this.onCommit = onCommit;
        this.onReadout = onReadout;
        this.onInfo = onInfo;

        this.tool = null;
        this.points = [];
        this.pointer = null;
        this.shift = false;
        this.attached = false;

        this.container = map.getContainer();

        this.handlers = {
            down: (event) => this.pointerDown(event),
            move: (event) => this.pointerMove(event),
            key: (event) => this.keyDown(event),
            keyUp: (event) => this.keyUp(event),
        };
    }

    setTool(tool) {
        this.cancel();
        this.tool = tool;

        if (tool === null) {
            this.detach();

            return;
        }

        this.attach();
        this.container.style.cursor = 'crosshair';
    }

    attach() {
        if (this.attached) {
            return;
        }

        this.attached = true;
        this.container.addEventListener('pointerdown', this.handlers.down);
        this.container.addEventListener('pointermove', this.handlers.move);
        window.addEventListener('keydown', this.handlers.key);
        window.addEventListener('keyup', this.handlers.keyUp);
        this.renderer.setEditPainter((context) => this.paint(context));
    }

    detach() {
        if (!this.attached) {
            return;
        }

        this.attached = false;
        this.container.removeEventListener('pointerdown', this.handlers.down);
        this.container.removeEventListener('pointermove', this.handlers.move);
        window.removeEventListener('keydown', this.handlers.key);
        window.removeEventListener('keyup', this.handlers.keyUp);
        this.renderer.setEditPainter(null);
        this.container.style.cursor = '';
    }

    /** Throw away the work in progress, keeping the tool selected. */
    cancel() {
        this.points = [];
        this.pointer = null;
        this.renderer.schedule();
        this.report();
    }

    /** The lon-lat under a pointer event. */
    at(event) {
        const box = this.container.getBoundingClientRect();
        const latLng = this.map.containerPointToLatLng([
            event.clientX - box.left,
            event.clientY - box.top,
        ]);

        return [latLng.lng, latLng.lat];
    }

    constrained(point) {
        const anchor = this.points[this.points.length - 1];

        return this.shift && anchor ? constrainToAngle(anchor, point) : point;
    }

    pointerDown(event) {
        if (!this.tool || event.button !== 0) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        if (this.tool === 'info') {
            const box = this.container.getBoundingClientRect();

            this.onInfo?.([event.clientX - box.left, event.clientY - box.top]);

            return;
        }

        this.points.push(this.constrained(this.at(event)));

        const [, maximum] = TOOL_POINTS[this.tool] ?? [0, Infinity];

        if (FIXED.has(this.tool) && this.points.length >= maximum) {
            this.finish();

            return;
        }

        this.renderer.schedule();
        this.report();
    }

    pointerMove(event) {
        if (!this.tool || this.tool === 'info') {
            return;
        }

        this.pointer = this.at(event);
        this.renderer.schedule();
        this.report();
    }

    keyDown(event) {
        if (!this.tool) {
            return;
        }

        if (event.key === 'Shift') {
            this.shift = true;

            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            this.cancel();

            return;
        }

        if (event.key === 'Backspace') {
            event.preventDefault();
            this.points.pop();
            this.renderer.schedule();
            this.report();

            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            this.finish();
        }
    }

    keyUp(event) {
        if (event.key === 'Shift') {
            this.shift = false;
        }
    }

    /**
     * Turn what has been measured into an annotation and hand it over.
     *
     * The value is computed from the finished geometry by `valueFor`, never
     * carried over from the live readout. The readout measures the preview,
     * which includes wherever the pointer happens to be — committing that
     * number would save a figure a pixel or two different from the line beside
     * it, and nobody would ever notice why.
     */
    finish() {
        const tool = this.tool;
        const geometry = geometryFor(tool, this.points);

        this.cancel();

        if (!geometry) {
            return;
        }

        const kind = TOOL_KIND[tool];

        this.onCommit?.({ kind, tool, geom: geometry, value: valueFor(kind, geometry) });
    }

    /** The points as they would be if the pointer placed one now. */
    preview() {
        if (this.pointer === null || this.points.length === 0) {
            return this.points;
        }

        return [...this.points, this.constrained(this.pointer)];
    }

    /** The live figures, in the shape the toolbar's readout already renders. */
    report() {
        if (!this.onReadout) {
            return;
        }

        if (!this.tool || this.tool === 'info' || this.points.length === 0) {
            this.onReadout(null);

            return;
        }

        const points = this.preview();
        const last = points[points.length - 1];
        const previous = points[points.length - 2];

        const readout = {
            tool: this.tool,
            vertices: this.points.length,
            segment: previous ? distance(previous, last) : 0,
            bearing: previous ? bearing(previous, last) : null,
            total: length(points),
        };

        if (this.tool === 'area') {
            readout.area = points.length >= 3 ? ringArea([...points, points[0]]) : 0;
        }

        if (this.tool === 'radius' || this.tool === 'diameter') {
            const radius = previous ? distance(points[0], last) : 0;

            readout.radius = this.tool === 'diameter' ? radius * 2 : radius;
            readout.total = 0;
        }

        this.onReadout(readout);
    }

    /**
     * The work in progress, on the edit canvas.
     *
     * Amber rather than the draw session's blue, because the two use the same
     * canvas and the same dashes and the only thing telling a user which one
     * is armed is what it looks like.
     */
    paint(context) {
        if (!this.tool || this.tool === 'info') {
            return;
        }

        const points = this.preview();

        if (points.length === 0) {
            return;
        }

        const ring = this.tool === 'area' && points.length >= 3 ? [...points, points[0]] : points;
        const screen = ring.map(([lng, lat]) => this.map.latLngToContainerPoint([lat, lng]));

        context.save();
        context.strokeStyle = '#b7791f';
        context.lineWidth = 2;
        context.setLineDash([6, 4]);
        context.beginPath();
        screen.forEach((point, index) => (index === 0
            ? context.moveTo(point.x, point.y)
            : context.lineTo(point.x, point.y)));
        context.stroke();
        context.setLineDash([]);

        for (const [lng, lat] of this.points) {
            const point = this.map.latLngToContainerPoint([lat, lng]);

            context.beginPath();
            context.arc(point.x, point.y, 4, 0, Math.PI * 2);
            context.fillStyle = '#ffffff';
            context.fill();
            context.strokeStyle = '#b7791f';
            context.lineWidth = 2;
            context.stroke();
        }

        context.restore();
    }
}
