/**
 * Selecting features by dragging a shape over them.
 *
 * Two gestures, one session: a **marquee** is a drag that becomes a rectangle,
 * a **lasso** is a drag that keeps every sample. They differ in four lines, and
 * the third thing they both are — a ring of points handed to the renderer —
 * is the only thing anything downstream cares about.
 *
 * **It answers from the loaded set only**, which §14 requires in as many words.
 * The client holds the viewport, not the layer: `Lot` is 1.4 million features
 * and a lasso cannot be the source of truth for "which features match". A
 * predicate over the whole layer is the query endpoint's job, and the panel
 * that runs it already exists.
 *
 * So the count this returns is a count of what is loaded, and the caller has
 * to say so — the same honesty the attribute table's row count already
 * practises.
 */

import { simplify } from '../draw/shapes.js';

/** Douglas-Peucker tolerance for a lasso stroke, in container pixels. */
const LASSO_TOLERANCE = 2;

/** Below this, a drag was a click that wobbled. */
const MIN_DRAG_PX = 4;

export class SelectSession {
    /**
     * @param {Object} options
     * @param {Object} options.map
     * @param {Object} options.renderer
     * @param {Function} options.onSelect (ids, {additive}) => void
     * @param {Function} options.onCount  (n) => void, live while dragging
     */
    constructor({ map, renderer, onSelect, onCount = null }) {
        this.map = map;
        this.renderer = renderer;
        this.onSelect = onSelect;
        this.onCount = onCount;

        this.mode = null;
        this.points = [];
        this.dragging = false;
        this.additive = false;
        this.attached = false;

        this.container = map.getContainer();

        this.handlers = {
            down: (event) => this.pointerDown(event),
            move: (event) => this.pointerMove(event),
            up: (event) => this.pointerUp(event),
            key: (event) => this.keyDown(event),
        };
    }

    /** `marquee`, `lasso`, or null to put the tool away. */
    setMode(mode) {
        this.cancel();
        this.mode = mode;

        if (mode === null) {
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
        this.renderer.setEditPainter(null);
        this.container.style.cursor = '';
    }

    cancel() {
        this.points = [];
        this.dragging = false;
        this.map.dragging.enable();
        this.renderer.schedule();
        this.onCount?.(null);
    }

    at(event) {
        const box = this.container.getBoundingClientRect();

        return { x: event.clientX - box.left, y: event.clientY - box.top };
    }

    pointerDown(event) {
        if (!this.mode || event.button !== 0) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        // Shift adds to the selection rather than replacing it, which is what
        // every file manager and every drawing program has taught. Captured at
        // the START of the drag: a user who releases shift while dragging did
        // not mean to change their mind about that.
        this.additive = event.shiftKey;
        this.dragging = true;
        this.points = [this.at(event)];
        this.map.dragging.disable();
    }

    pointerMove(event) {
        if (!this.dragging) {
            return;
        }

        const point = this.at(event);

        if (this.mode === 'lasso') {
            this.points.push(point);
        } else {
            this.points[1] = point;
        }

        this.renderer.schedule();

        // A live count while dragging, because the alternative is releasing
        // over four thousand parcels to find out.
        this.onCount?.(this.ring() ? this.renderer.searchShape(this.ring()).length : null);
    }

    pointerUp(event) {
        if (!this.dragging) {
            return;
        }

        const start = this.points[0];
        const end = this.at(event);

        this.dragging = false;
        this.map.dragging.enable();

        if (this.mode === 'marquee') {
            this.points[1] = end;
        } else {
            this.points.push(end);
        }

        const ring = this.ring();

        this.finish(ring);
    }

    finish(ring) {
        const additive = this.additive;

        // Cleared before the callback, so a handler that repaints is not
        // drawing a lasso that is already over.
        this.points = [];
        this.renderer.schedule();
        this.onCount?.(null);

        if (!ring) {
            return;
        }

        const hits = this.renderer.searchShape(ring);

        this.onSelect?.(hits.map((hit) => hit.id), { additive });
    }

    keyDown(event) {
        if (this.mode && event.key === 'Escape') {
            event.preventDefault();
            this.cancel();
        }
    }

    /**
     * The gesture as a closed ring of container points, or null if there is
     * not enough of it to enclose anything.
     */
    ring() {
        const points = this.points;

        if (points.length < 2 || !points[1]) {
            return null;
        }

        if (this.mode === 'marquee') {
            const [a, b] = points;

            if (Math.abs(b.x - a.x) < MIN_DRAG_PX && Math.abs(b.y - a.y) < MIN_DRAG_PX) {
                return null;
            }

            return [
                { x: a.x, y: a.y }, { x: b.x, y: a.y },
                { x: b.x, y: b.y }, { x: a.x, y: b.y },
            ];
        }

        // Simplified as it is tested, not only on release: a pointer emits a
        // sample every few milliseconds, and the live count would otherwise be
        // a four-hundred-sided polygon against every candidate, per move.
        const reduced = simplify(points.map((p) => [p.x, p.y]), LASSO_TOLERANCE);

        return reduced.length >= 3 ? reduced.map(([x, y]) => ({ x, y })) : null;
    }

    /** The shape being dragged, on the edit canvas. */
    paint(context) {
        const ring = this.ring();

        if (!ring) {
            return;
        }

        context.save();
        context.beginPath();
        ring.forEach((point, index) => (index === 0
            ? context.moveTo(point.x, point.y)
            : context.lineTo(point.x, point.y)));
        context.closePath();
        context.fillStyle = 'rgba(43, 108, 176, 0.12)';
        context.fill();
        context.strokeStyle = '#2b6cb0';
        context.lineWidth = 1.5;
        context.setLineDash([5, 3]);
        context.stroke();
        context.restore();
    }
}

/** The selection gestures, in the order the toolbar shows them. */
export const SELECT_MODES = ['marquee', 'lasso'];
