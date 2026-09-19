/**
 * The four grab points of an image overlay, and the body between them.
 *
 * Drawn on the **edit canvas**, so dragging a corner clears and repaints a
 * handful of shapes rather than every parcel in view — consistent with vertex
 * editing, and the reason that canvas exists (specification section 9).
 *
 * A drag updates the overlay live and commits once, on release. The rule is the
 * same one the opacity slider follows: a pointer gesture produces a continuous
 * stream of positions and exactly one command, or the undo stack fills with a
 * step per pixel and the wire carries a round trip per frame.
 *
 * **There is a fifth handle, and it rotates all four corners together.** A
 * scanned sheet is almost never square to north, and squaring it a corner at a
 * time means four drags that each undo part of the last one — the quad shears
 * before it turns. The rotate handle turns the whole quad about its centre and
 * leaves its shape alone, which is the move a user actually wants first.
 *
 * **Rotation happens in screen pixels, never in degrees.** At this latitude a
 * degree of longitude is about a tenth shorter than a degree of latitude, so
 * rotating the stored lon-lat pairs would squash the image as it turned. The
 * corners are projected to the container, turned there, and unprojected back.
 */

import { CORNERS } from './image-overlay.js';

const HANDLE_RADIUS = 7;
const GRAB_RADIUS = 14;

/** How far beyond the top edge the rotate handle floats, in screen pixels. */
const ROTATE_OFFSET_PX = 28;

export class CornerHandles {
    /**
     * @param {Object} options
     * @param {Object} options.map the Leaflet map
     * @param {Object} options.renderer supplies the edit canvas
     * @param {Function} options.onCommit called with the final corners
     * @param {Function} options.onPreview called with corners during a drag
     */
    constructor({ map, renderer, onCommit, onPreview }) {
        this.map = map;
        this.renderer = renderer;
        this.onCommit = onCommit;
        this.onPreview = onPreview;

        this.target = null;
        this.drag = null;

        this.container = map.getContainer();
        this.onDown = (event) => this.pointerDown(event);
        this.onMove = (event) => this.pointerMove(event);
        this.onUp = (event) => this.pointerUp(event);

        this.container.addEventListener('pointerdown', this.onDown);
        this.container.addEventListener('pointermove', this.onMove);
        this.container.addEventListener('pointerup', this.onUp);
        this.container.addEventListener('pointercancel', this.onUp);
    }

    /**
     * Edit this overlay, or none.
     *
     * @param {{placementId: number, layerId: number, version: number, corners: Object}|null} target
     */
    setTarget(target) {
        this.target = target;
        this.drag = null;
        this.renderer.setEditPainter(target === null ? null : (context) => this.paint(context));
    }

    /** The corners in container pixels, in `CORNERS` order. */
    points() {
        return CORNERS.map((name) => {
            const [lng, lat] = this.target.corners[name];
            const point = this.map.latLngToContainerPoint([lat, lng]);

            return { name, x: point.x, y: point.y };
        });
    }

    /** The quad's centre, which is what rotation turns about. */
    centre(points = this.points()) {
        return {
            x: points.reduce((sum, p) => sum + p.x, 0) / points.length,
            y: points.reduce((sum, p) => sum + p.y, 0) / points.length,
        };
    }

    /**
     * Where the rotate handle floats: off the top edge, along its outward
     * normal.
     *
     * Anchored to the edge rather than to a fixed screen direction, so it
     * follows the image round as it turns and stays on the side the user thinks
     * of as the top. The offset is in screen pixels, so it does not shrink to
     * nothing when the overlay is small or when the map zooms out.
     */
    rotatePoint(points = this.points()) {
        const [nw, ne] = points;
        const midX = (nw.x + ne.x) / 2;
        const midY = (nw.y + ne.y) / 2;
        const centre = this.centre(points);

        // Away from the middle of the quad, which is "outward" whichever way
        // the image has been turned or flipped.
        let dx = midX - centre.x;
        let dy = midY - centre.y;
        const length = Math.hypot(dx, dy);

        if (length < 1e-6) {
            // A collapsed quad has no outward direction; straight up is as
            // good an answer as any and keeps the handle reachable.
            dx = 0;
            dy = -1;
        } else {
            dx /= length;
            dy /= length;
        }

        return {
            name: 'rotate',
            x: midX + dx * ROTATE_OFFSET_PX,
            y: midY + dy * ROTATE_OFFSET_PX,
            anchorX: midX,
            anchorY: midY,
        };
    }

    paint(context) {
        const points = this.points();

        context.save();
        context.strokeStyle = '#2b6cb0';
        context.lineWidth = 1.5;
        context.setLineDash([6, 4]);

        // The outline is drawn corner to corner rather than as a rectangle:
        // once a corner has moved, the quad is not a rectangle any more, and a
        // rectangle would stop matching the image under it.
        context.beginPath();
        points.forEach(({ x, y }, i) => (i === 0 ? context.moveTo(x, y) : context.lineTo(x, y)));
        context.closePath();
        context.stroke();

        context.setLineDash([]);

        // The stalk, so the rotate handle reads as attached to the top edge
        // rather than floating loose near it.
        const rotate = this.rotatePoint(points);

        context.beginPath();
        context.moveTo(rotate.anchorX, rotate.anchorY);
        context.lineTo(rotate.x, rotate.y);
        context.strokeStyle = '#2b6cb0';
        context.lineWidth = 1.5;
        context.stroke();

        for (const { x, y } of points) {
            context.beginPath();
            context.arc(x, y, HANDLE_RADIUS, 0, Math.PI * 2);
            context.fillStyle = '#ffffff';
            context.fill();
            context.strokeStyle = '#2b6cb0';
            context.lineWidth = 2;
            context.stroke();
        }

        // Filled rather than hollow, so it is obviously a different control
        // from the four that move a single corner.
        context.beginPath();
        context.arc(rotate.x, rotate.y, HANDLE_RADIUS, 0, Math.PI * 2);
        context.fillStyle = '#2b6cb0';
        context.fill();
        context.strokeStyle = '#ffffff';
        context.lineWidth = 2;
        context.stroke();

        context.restore();
    }

    /** The handle under a point, or null. */
    handleAt(x, y) {
        const points = this.points();
        // Tested first: it sits outside the quad, but a small or heavily
        // keystoned overlay can bring a corner within grabbing distance of it,
        // and rotating by accident is the more surprising of the two.
        const rotate = this.rotatePoint(points);

        if (Math.hypot(rotate.x - x, rotate.y - y) <= GRAB_RADIUS) {
            return 'rotate';
        }

        for (const point of points) {
            if (Math.hypot(point.x - x, point.y - y) <= GRAB_RADIUS) {
                return point.name;
            }
        }

        return null;
    }

    /** All four corners turned about the quad's centre, in screen space. */
    rotatedCorners(from, radians) {
        const points = CORNERS.map((name) => {
            const [lng, lat] = from[name];
            const point = this.map.latLngToContainerPoint([lat, lng]);

            return { name, x: point.x, y: point.y };
        });

        const centre = this.centre(points);
        const cos = Math.cos(radians);
        const sin = Math.sin(radians);
        const corners = {};

        for (const { name, x, y } of points) {
            const dx = x - centre.x;
            const dy = y - centre.y;
            const latLng = this.map.containerPointToLatLng([
                centre.x + dx * cos - dy * sin,
                centre.y + dx * sin + dy * cos,
            ]);

            corners[name] = [latLng.lng, latLng.lat];
        }

        return corners;
    }

    pointerDown(event) {
        if (!this.target || this.drag) {
            return;
        }

        const box = this.container.getBoundingClientRect();
        const x = event.clientX - box.left;
        const y = event.clientY - box.top;
        const name = this.handleAt(x, y);

        if (name === null) {
            return;
        }

        // Leaflet would otherwise start panning the map under the drag.
        event.preventDefault();
        event.stopPropagation();
        this.map.dragging.disable();

        const centre = this.centre();

        this.drag = {
            pointerId: event.pointerId,
            name,
            // Where the corners were before this gesture, which is what the
            // command's inverse has to restore — not where they were on the
            // last previewed frame.
            from: { ...this.target.corners },
            // Rotation is relative: the image turns by however far the pointer
            // has swept since it was grabbed, so grabbing the handle off-centre
            // does not snap the overlay round to meet it.
            startAngle: Math.atan2(y - centre.y, x - centre.x),
        };
    }

    pointerMove(event) {
        if (!this.drag || event.pointerId !== this.drag.pointerId) {
            return;
        }

        event.preventDefault();

        const box = this.container.getBoundingClientRect();
        const x = event.clientX - box.left;
        const y = event.clientY - box.top;

        if (this.drag.name === 'rotate') {
            const centre = this.centre();
            const angle = Math.atan2(y - centre.y, x - centre.x);

            this.target.corners = this.rotatedCorners(this.drag.from, angle - this.drag.startAngle);
        } else {
            const latLng = this.map.containerPointToLatLng([x, y]);

            this.target.corners = {
                ...this.target.corners,
                [this.drag.name]: [latLng.lng, latLng.lat],
            };
        }

        this.onPreview?.(this.target);
        this.renderer.schedule();
    }

    pointerUp(event) {
        if (!this.drag || (event && event.pointerId !== this.drag.pointerId)) {
            return;
        }

        const { from } = this.drag;
        const to = { ...this.target.corners };

        this.drag = null;
        this.map.dragging.enable();

        // Wound back before committing, so the command's inverse undoes to
        // where the gesture started rather than to the last preview.
        this.target.corners = from;

        this.onCommit?.(this.target, to);
    }

    destroy() {
        this.container.removeEventListener('pointerdown', this.onDown);
        this.container.removeEventListener('pointermove', this.onMove);
        this.container.removeEventListener('pointerup', this.onUp);
        this.container.removeEventListener('pointercancel', this.onUp);
        this.renderer.setEditPainter(null);
    }
}
