/**
 * Drawing geometry with a pointer, and the live readout that goes with it.
 *
 * **The pointer is one of two equal paths.** This tool is for surveying,
 * planning and cadastral work, where exact dimensions are the entire point, so
 * every tool also takes typed numbers — and both paths end in the same
 * functions in `shapes.js`, which is the only way to be sure a shape drawn and
 * a shape typed are the same object (specification section 9).
 *
 * The work in progress is painted on the **edit canvas**, so placing a vertex
 * never repaints the features underneath. If drawing ever triggers a feature
 * repaint, the canvas separation from S2 has been broken.
 */

import { destination, distance, bearing, length, ringArea } from '../../lib/measure.js';
import {
    rectangleFromDrag, circleRing, radiusFromDrag, constrainToAngle, simplify,
} from './shapes.js';

/** Tools that finish on a release rather than on a click per vertex. */
const DRAG_TOOLS = new Set(['rectangle', 'circle', 'freehand']);

/** Douglas-Peucker tolerance for a freehand stroke, in degrees. */
const FREEHAND_TOLERANCE = 2e-6;

export class DrawSession {
    /**
     * @param {Object} options
     * @param {Object} options.map the Leaflet map
     * @param {Object} options.renderer supplies the edit canvas
     * @param {Function} options.onCommit called with a finished GeoJSON geometry
     * @param {Function} options.onReadout called with the live measurements
     * @param {Function} options.onToolChange called when the active tool changes
     */
    constructor({ map, renderer, onCommit, onReadout, onToolChange }) {
        this.map = map;
        this.renderer = renderer;
        this.onCommit = onCommit;
        this.onReadout = onReadout;
        this.onToolChange = onToolChange;

        this.tool = null;
        this.points = [];
        this.pointer = null;
        this.shift = false;
        this.dragging = false;

        this.container = map.getContainer();

        this.handlers = {
            down: (event) => this.pointerDown(event),
            move: (event) => this.pointerMove(event),
            up: (event) => this.pointerUp(event),
            key: (event) => this.keyDown(event),
            keyUp: (event) => this.keyUp(event),
        };
    }

    /** Begin drawing with a tool, or stop drawing when given null. */
    setTool(tool) {
        this.cancel();
        this.tool = tool;
        this.onToolChange?.(tool);

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
        this.container.addEventListener('pointerup', this.handlers.up);
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
        this.container.removeEventListener('pointerup', this.handlers.up);
        window.removeEventListener('keydown', this.handlers.key);
        window.removeEventListener('keyup', this.handlers.keyUp);
        this.renderer.setEditPainter(null);
        this.container.style.cursor = '';
    }

    /** Throw away the work in progress, keeping the tool selected. */
    cancel() {
        this.points = [];
        this.pointer = null;
        this.dragging = false;
        this.map.dragging.enable();
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

    /**
     * Where the next vertex would go.
     *
     * `Shift` turns the segment onto the nearest 15°, measured from the vertex
     * already placed — so the constraint is about the segment being drawn, not
     * about the whole shape.
     */
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

        const point = this.at(event);

        if (this.tool === 'point') {
            this.commit({ type: 'Point', coordinates: point });

            return;
        }

        if (DRAG_TOOLS.has(this.tool)) {
            this.dragging = true;
            this.points = [point];
            this.map.dragging.disable();

            return;
        }

        // Closing a ring by clicking its first vertex. The tolerance is in
        // screen pixels, because that is what the user is aiming with.
        if (this.tool === 'polygon' && this.points.length >= 3 && this.nearFirst(event)) {
            this.finish();

            return;
        }

        this.points.push(this.constrained(point));
        this.renderer.schedule();
        this.report();
    }

    pointerMove(event) {
        if (!this.tool) {
            return;
        }

        this.pointer = this.at(event);

        if (this.dragging && this.tool === 'freehand') {
            this.points.push(this.pointer);
        }

        this.renderer.schedule();
        this.report();
    }

    pointerUp(event) {
        if (!this.tool || !this.dragging) {
            return;
        }

        this.dragging = false;
        this.map.dragging.enable();
        this.points.push(this.at(event));
        this.finish();
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

    /** Is the pointer over the ring's first vertex? */
    nearFirst(event) {
        if (this.points.length === 0) {
            return false;
        }

        const box = this.container.getBoundingClientRect();
        const first = this.map.latLngToContainerPoint([this.points[0][1], this.points[0][0]]);

        // 10 px fine, 18 px coarse — a fingertip needs more room than a mouse
        // (specification section 9).
        const tolerance = window.matchMedia?.('(pointer: coarse)').matches ? 18 : 10;

        return Math.hypot(
            first.x - (event.clientX - box.left),
            first.y - (event.clientY - box.top),
        ) <= tolerance;
    }

    /** Turn what has been drawn into geometry and hand it over. */
    finish() {
        const geometry = this.geometry();

        this.cancel();

        if (geometry) {
            this.commit(geometry);
        }
    }

    /**
     * The geometry the current points describe, or null if there is not enough
     * of it yet.
     */
    geometry() {
        const points = this.points;

        switch (this.tool) {
            case 'line':
                return points.length >= 2
                    ? { type: 'LineString', coordinates: points }
                    : null;

            case 'polygon':
                return points.length >= 3
                    ? { type: 'Polygon', coordinates: [[...points, points[0]]] }
                    : null;

            case 'rectangle':
                return points.length >= 2
                    ? { type: 'Polygon', coordinates: [rectangleFromDrag(points[0], points[points.length - 1])] }
                    : null;

            case 'circle': {
                if (points.length < 2) {
                    return null;
                }

                const radius = radiusFromDrag(points[0], points[points.length - 1]);

                return radius > 0
                    ? { type: 'Polygon', coordinates: [circleRing(points[0], radius)] }
                    : null;
            }

            case 'freehand': {
                // Simplified on release, once, before it becomes a feature. A
                // pointer emits a sample every few milliseconds and nobody
                // means to place four hundred vertices along one kerb.
                const reduced = simplify(points, FREEHAND_TOLERANCE);

                return reduced.length >= 2
                    ? { type: 'LineString', coordinates: reduced }
                    : null;
            }

            default:
                return null;
        }
    }

    commit(geometry) {
        this.onCommit?.(geometry);
    }

    /**
     * The live readout: this segment, the total, and the area if it encloses
     * one.
     *
     * Recomputed per pointer move, which is affordable because it is a handful
     * of geodesic solutions rather than one per vertex — the running total is
     * the only thing that walks the whole path.
     */
    report() {
        if (!this.onReadout) {
            return;
        }

        if (!this.tool || (this.points.length === 0 && !this.dragging)) {
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

        if (this.tool === 'circle' && points.length >= 2) {
            readout.radius = radiusFromDrag(points[0], last);
            readout.area = Math.PI * readout.radius ** 2;
        } else if (this.tool === 'polygon' || this.tool === 'rectangle') {
            readout.area = points.length >= 3 ? ringArea([...points, points[0]]) : 0;
        }

        this.onReadout(readout);
    }

    /** The points as they would be if the pointer placed one now. */
    preview() {
        if (this.pointer === null || this.dragging || this.points.length === 0) {
            return this.points;
        }

        return [...this.points, this.constrained(this.pointer)];
    }

    /**
     * Paint the work in progress.
     *
     * Deliberately plain: a dashed line, a dot per placed vertex, and a hollow
     * ring on the first vertex of a polygon once closing it is possible, which
     * is the only affordance here that says something the user cannot see.
     */
    paint(context) {
        if (!this.tool) {
            return;
        }

        const points = this.shapePreview();

        if (points.length === 0) {
            return;
        }

        const screen = points.map(([lng, lat]) => this.map.latLngToContainerPoint([lat, lng]));

        context.save();
        context.strokeStyle = '#2b6cb0';
        context.lineWidth = 2;
        context.setLineDash([6, 4]);
        context.beginPath();
        screen.forEach((p, i) => (i === 0 ? context.moveTo(p.x, p.y) : context.lineTo(p.x, p.y)));
        context.stroke();
        context.setLineDash([]);

        for (const point of this.points.map(([lng, lat]) => this.map.latLngToContainerPoint([lat, lng]))) {
            context.beginPath();
            context.arc(point.x, point.y, 4, 0, Math.PI * 2);
            context.fillStyle = '#ffffff';
            context.fill();
            context.strokeStyle = '#2b6cb0';
            context.lineWidth = 2;
            context.stroke();
        }

        if (this.tool === 'polygon' && this.points.length >= 3) {
            const first = screen[0];

            context.beginPath();
            context.arc(first.x, first.y, 9, 0, Math.PI * 2);
            context.strokeStyle = '#2f855a';
            context.lineWidth = 2;
            context.stroke();
        }

        context.restore();
    }

    /** What to draw: the shape tools show their result, not their two points. */
    shapePreview() {
        if (DRAG_TOOLS.has(this.tool) && this.tool !== 'freehand') {
            const preview = this.dragging && this.pointer
                ? [this.points[0], this.pointer]
                : this.points;

            if (preview.length < 2) {
                return [];
            }

            return this.tool === 'rectangle'
                ? rectangleFromDrag(preview[0], preview[1])
                : circleRing(preview[0], radiusFromDrag(preview[0], preview[1]));
        }

        const points = this.preview();

        return this.tool === 'polygon' && points.length >= 3 ? [...points, points[0]] : points;
    }
}

/** Every tool this session knows, in the order the toolbar shows them. */
export const TOOLS = ['point', 'line', 'polygon', 'rectangle', 'circle', 'freehand'];

/** Re-exported so the numeric-entry panel does not import two modules. */
export { destination };
