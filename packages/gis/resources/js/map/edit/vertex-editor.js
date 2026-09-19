/**
 * Moving, inserting and deleting the vertices of an existing feature.
 *
 * Handles are drawn on the **edit canvas**, so dragging one repaints a few
 * dozen shapes rather than every parcel in view. If dragging a vertex ever
 * triggers a feature repaint, the canvas separation from S2 has been broken
 * (specification section 9).
 *
 * **The feature's coordinates are read back out of the renderer**, not fetched
 * again. They are already there — stored projected, because that is what the
 * draw cycle and the index want — so editing unprojects the one feature it is
 * working on and projects the result back. Asking the server for geometry the
 * client is already holding would put a round trip inside a gesture.
 *
 * A whole drag is one undo step and one command, on release. A command per
 * pointer move would be a round trip and a reconcile per pixel, and an undo
 * stack that takes forty presses to get back to where a drag started.
 */

import { unprojectX, unprojectY } from '../geometry.js';

/**
 * Is this a coarse pointer — a finger rather than a mouse?
 *
 * Guarded, because these modules are unit-tested under Node where there is no
 * `window` at all. An unguarded `window.matchMedia` throws there, and the
 * failure looks like the arithmetic being wrong rather than the environment.
 */
function coarsePointer() {
    return typeof window !== 'undefined'
        && window.matchMedia?.('(pointer: coarse)').matches === true;
}

/** Grab radius, by pointer type. Section 9: 10 px fine, 18 px coarse. */
function grabRadius() {
    return coarsePointer() ? 18 : 10;
}

/**
 * One feature's rings, as longitude and latitude.
 *
 * Works from the flat arrays the renderer holds: `featStarts` says which rings
 * belong to the feature, `ringStarts` where each ring's vertices begin.
 */
export function ringsOf(geometry, feature) {
    const { coords, ringStarts, featStarts } = geometry;
    const rings = [];

    for (let r = featStarts[feature]; r < featStarts[feature + 1]; r++) {
        const ring = [];

        for (let v = ringStarts[r]; v < ringStarts[r + 1]; v++) {
            ring.push([unprojectX(coords[v * 2]), unprojectY(coords[v * 2 + 1])]);
        }

        rings.push(ring);
    }

    return rings;
}

/** Is this ring closed — does its last position repeat its first? */
export function isClosed(ring) {
    return ring.length > 2
        && ring[0][0] === ring[ring.length - 1][0]
        && ring[0][1] === ring[ring.length - 1][1];
}

/**
 * The GeoJSON a set of rings describes, given the type the feature was.
 *
 * A polygon's rings are closed already, because that is how they were stored —
 * the validator closes every ring on the way in, so nothing here has to.
 */
export function geometryOf(type, rings) {
    if (type === 1) {
        return { type: 'Point', coordinates: rings[0][0] };
    }

    if (type === 2) {
        return rings.length === 1
            ? { type: 'LineString', coordinates: rings[0] }
            : { type: 'MultiLineString', coordinates: rings };
    }

    return { type: 'Polygon', coordinates: rings };
}

/**
 * Every draggable handle for a set of rings: a vertex, and a midpoint between
 * each pair.
 *
 * Midpoints are handles too, and dragging one inserts a vertex there. That is
 * the standard gesture and it avoids a separate "insert" mode — which would be
 * a mode the user has to remember to leave.
 *
 * A closed ring's last position repeats its first, so it gets no handle of its
 * own: two handles on one point that moved independently would tear the ring.
 */
export function handlesFor(rings) {
    const out = [];

    rings.forEach((ring, r) => {
        const closed = isClosed(ring);
        const count = closed ? ring.length - 1 : ring.length;

        for (let v = 0; v < count; v++) {
            out.push({ kind: 'vertex', ring: r, index: v, point: ring[v] });
        }

        for (let v = 0; v < (closed ? count : count - 1); v++) {
            const a = ring[v];
            const b = ring[(v + 1) % count];

            out.push({
                kind: 'midpoint',
                ring: r,
                index: v + 1,
                point: [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2],
            });
        }
    });

    return out;
}

/**
 * One vertex removed, or null when removing it would leave a shape that is not
 * one.
 *
 * Refused rather than repaired: the alternative is deleting the feature out
 * from under a keypress that was aimed at a corner of it.
 */
export function withoutVertex(rings, ringIndex, index, type) {
    const next = rings.map((ring) => ring.map((p) => [...p]));
    const ring = next[ringIndex];
    const closed = isClosed(ring);
    const corners = closed ? ring.length - 1 : ring.length;
    const floor = type === 3 ? 3 : 2;

    if (corners <= floor) {
        return null;
    }

    ring.splice(index, 1);

    if (closed && index === 0) {
        ring[ring.length - 1] = [...ring[0]];
    }

    return next;
}

export class VertexEditor {
    /**
     * @param {Object} options
     * @param {Object} options.map the Leaflet map
     * @param {Object} options.renderer supplies the edit canvas and the geometry
     * @param {Object} options.snap a `Snapper`, or null
     * @param {Function} options.onCommit called with (target, geometry)
     */
    constructor({ map, renderer, snap = null, onCommit }) {
        this.map = map;
        this.renderer = renderer;
        this.snap = snap;
        this.onCommit = onCommit;

        this.target = null;
        this.drag = null;
        this.hover = null;
        this.container = map.getContainer();

        this.handlers = {
            down: (event) => this.pointerDown(event),
            move: (event) => this.pointerMove(event),
            up: (event) => this.pointerUp(event),
            key: (event) => this.keyDown(event),
        };
    }

    /** Edit this feature, or none. */
    setTarget(target) {
        this.drag = null;
        this.hover = null;

        if (target === null) {
            this.target = null;
            this.detach();
            this.renderer.setEditPainter(null);
            this.renderer.schedule();

            return;
        }

        const layer = this.renderer.layerAt(target.slot);

        if (!layer) {
            this.target = null;

            return;
        }

        this.target = { ...target, rings: ringsOf(layer.geometry, target.feature) };
        this.attach();
        this.renderer.setEditPainter((context) => this.paint(context));
        this.renderer.schedule();
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
        this.container.style.cursor = '';
    }

    toScreen([lng, lat]) {
        return this.map.latLngToContainerPoint([lat, lng]);
    }

    at(event) {
        const box = this.container.getBoundingClientRect();
        const latLng = this.map.containerPointToLatLng([
            event.clientX - box.left,
            event.clientY - box.top,
        ]);

        return [latLng.lng, latLng.lat];
    }

    handles() {
        return handlesFor(this.target.rings);
    }

    /** The handle under a point, vertices winning ties with midpoints. */
    handleAt(x, y) {
        const radius = grabRadius();
        let best = null;
        let bestDistance = Infinity;

        for (const handle of this.handles()) {
            const screen = this.toScreen(handle.point);
            const d = Math.hypot(screen.x - x, screen.y - y);

            if (d > radius) {
                continue;
            }

            // A vertex beats a midpoint even when the midpoint is nearer:
            // moving or deleting an existing corner is the commoner intent,
            // and inserting one a pixel from a corner is almost never meant.
            const better = best === null
                || (handle.kind === 'vertex' && best.kind === 'midpoint')
                || (handle.kind === best.kind && d < bestDistance);

            if (better) {
                best = handle;
                bestDistance = d;
            }
        }

        return best;
    }

    pointerDown(event) {
        if (!this.target || event.button !== 0) {
            return;
        }

        const box = this.container.getBoundingClientRect();
        const handle = this.handleAt(event.clientX - box.left, event.clientY - box.top);

        if (!handle) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        this.map.dragging.disable();

        const rings = this.target.rings.map((ring) => ring.map((p) => [...p]));

        if (handle.kind === 'midpoint') {
            // Inserted on grab rather than on release, so the new vertex is
            // under the pointer for the whole drag instead of appearing at the
            // end of it.
            rings[handle.ring].splice(handle.index, 0, [...handle.point]);
        }

        this.drag = {
            pointerId: event.pointerId,
            ring: handle.ring,
            index: handle.index,
            // Recorded NOW, before the first move mutates vertex zero. Asking
            // `isClosed` afterwards answers about the ring as it has already
            // become — and once vertex zero has moved, its last position no
            // longer repeats it, so the ring reads as open and the guard that
            // keeps it closed never fires.
            closed: isClosed(rings[handle.ring]),
            // What the inverse restores: where the rings were before the
            // gesture, not where they were on the last previewed frame.
            from: this.target.rings,
        };

        this.target.rings = rings;
        this.renderer.schedule();
    }

    pointerMove(event) {
        const box = this.container.getBoundingClientRect();
        const x = event.clientX - box.left;
        const y = event.clientY - box.top;

        if (!this.drag) {
            if (this.target) {
                const handle = this.handleAt(x, y);
                const next = handle ? `${handle.kind}:${handle.ring}:${handle.index}` : null;

                if (next !== this.hover) {
                    this.hover = next;
                    this.container.style.cursor = handle ? 'move' : '';
                    this.renderer.schedule();
                }
            }

            return;
        }

        event.preventDefault();

        let point = this.at(event);

        // Alt suppresses snapping, which is how a vertex is placed deliberately
        // close to another without being pulled onto it.
        if (this.snap && !event.altKey) {
            point = this.snap.snap(point, { excludeFeature: this.target.feature }) ?? point;
        }

        this.target.rings[this.drag.ring][this.drag.index] = point;
        this.closeRing(this.target.rings[this.drag.ring], this.drag.index, this.drag.closed);
        this.renderer.schedule();
    }

    /**
     * Keep a closed ring closed when its first vertex moves.
     *
     * The last position repeats the first and has no handle of its own, so
     * moving vertex zero has to carry it. Without this the ring tears open,
     * the validator closes it by appending the new first position, and the
     * shape quietly gains a corner — measured: a square came back a pentagon.
     *
     * `closed` is passed in rather than tested, because by the time this runs
     * the mutation has already happened and the ring no longer looks closed.
     */
    closeRing(ring, moved, closed) {
        if (moved === 0 && closed) {
            ring[ring.length - 1] = [...ring[0]];
        }
    }

    pointerUp(event) {
        if (!this.drag || (event && event.pointerId !== this.drag.pointerId)) {
            return;
        }

        const { from } = this.drag;
        const rings = this.target.rings;

        this.drag = null;
        this.map.dragging.enable();
        this.target.rings = from;

        this.commit(rings);
    }

    keyDown(event) {
        if (!this.target || this.drag) {
            return;
        }

        if (event.key !== 'Delete' && event.key !== 'Backspace') {
            return;
        }

        const [kind, ring, index] = (this.hover ?? '').split(':');

        if (kind !== 'vertex') {
            return;
        }

        const rings = withoutVertex(this.target.rings, Number(ring), Number(index), this.target.type);

        if (rings === null) {
            return;
        }

        event.preventDefault();
        this.commit(rings);
    }

    commit(rings) {
        this.hover = null;
        this.onCommit?.(this.target, geometryOf(this.target.type, rings));
    }

    /** Take the geometry back after the server has confirmed it. */
    reload() {
        if (this.target) {
            this.setTarget({ ...this.target });
        }
    }

    paint(context) {
        if (!this.target) {
            return;
        }

        context.save();

        for (const handle of this.handles()) {
            const { x, y } = this.toScreen(handle.point);
            const active = this.hover === `${handle.kind}:${handle.ring}:${handle.index}`;

            context.beginPath();

            if (handle.kind === 'vertex') {
                context.rect(x - 4, y - 4, 8, 8);
                context.fillStyle = active ? '#2b6cb0' : '#ffffff';
            } else {
                // Smaller and paler: a midpoint is an invitation rather than
                // something that is already there.
                context.arc(x, y, 3, 0, Math.PI * 2);
                context.fillStyle = active ? '#2b6cb0' : 'rgba(255, 255, 255, 0.7)';
            }

            context.fill();
            context.strokeStyle = '#2b6cb0';
            context.lineWidth = active ? 2.5 : 1.5;
            context.stroke();
        }

        context.restore();
    }
}
