/**
 * Snapping a point onto nearby geometry.
 *
 * **Candidates are ranked, not merely nearest.** Section 9 sets the order —
 * vertex, midpoint, nearest-on-edge — and the order is the point: a user
 * aiming at a corner wants the corner, even when the edge running through it
 * happens to pass a pixel closer to where they released. Nearest-wins snapping
 * is the version that feels like it is fighting you.
 *
 * Candidates come from the spatial index within a pixel tolerance, so the cost
 * is a small box query and a walk over what it returns — not a scan of the
 * loaded set, which at zoom 12 is tens of thousands of features.
 *
 * `Alt` suppresses it entirely, which is how a vertex is placed deliberately
 * close to another without being pulled onto it. The caller owns that key; this
 * class only knows how to find things.
 */

import { projectLng, projectLat, unprojectX, unprojectY } from './geometry.js';

/** Rank order. Lower wins, whatever the distances are. */
const RANK = { vertex: 0, midpoint: 1, edge: 2 };

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


export class Snapper {
    /**
     * @param {Object} options
     * @param {Object} options.map the Leaflet map
     * @param {Function} options.layers () => the renderer's drawn layers
     * @param {Object} options.enabled which candidate kinds are live
     */
    constructor({ map, layers, enabled = { vertex: true, midpoint: true, edge: true } }) {
        this.map = map;
        this.layers = layers;
        this.enabled = enabled;
    }

    /** Tolerance in screen pixels, by pointer type (section 9). */
    tolerancePx() {
        return coarsePointer() ? 18 : 10;
    }

    /**
     * The tolerance as a distance in projected units, here and now.
     *
     * Derived from the zoom rather than stored, because a pixel is a different
     * distance on the ground at every zoom — a fixed ground tolerance would be
     * unusable at one end of the range and invisible at the other.
     */
    tolerance() {
        return this.tolerancePx() / (256 * 2 ** this.map.getZoom());
    }

    /**
     * The best candidate for a lon-lat point, or null.
     *
     * @param {number[]} point [longitude, latitude]
     * @param {Object} options
     * @param {number|null} options.excludeFeature a feature index to ignore
     * @param {number|null} options.excludeSlot the renderer slot it lives in
     */
    snap(point, { excludeFeature = null, excludeSlot = null } = {}) {
        const x = projectLng(point[0]);
        const y = projectLat(point[1]);
        const tolerance = this.tolerance();
        const best = this.candidate(x, y, tolerance, excludeFeature, excludeSlot);

        return best === null ? null : [unprojectX(best.x), unprojectY(best.y)];
    }

    /** The winning candidate in projected units, or null. */
    candidate(x, y, tolerance, excludeFeature, excludeSlot) {
        let best = null;

        for (const { slot, layer } of this.layers()) {
            if (!layer.visible || !layer.index) {
                continue;
            }

            const found = layer.index.search(x - tolerance, y - tolerance, x + tolerance, y + tolerance);
            const { coords, ringStarts, featStarts } = layer.geometry;

            for (let i = 0; i < found.length; i++) {
                const feature = found[i];

                // A vertex must not snap to the feature it belongs to, or
                // dragging one corner of a square onto the next would weld
                // them. Closing a ring is the deliberate exception and the
                // drawing tool handles it separately.
                if (slot === excludeSlot && feature === excludeFeature) {
                    continue;
                }

                for (let r = featStarts[feature]; r < featStarts[feature + 1]; r++) {
                    best = this.scanRing(coords, ringStarts[r], ringStarts[r + 1], x, y, tolerance, best);
                }
            }
        }

        return best;
    }

    /** Vertices, midpoints and edge projections of one ring. */
    scanRing(coords, from, to, x, y, tolerance, best) {
        const consider = (kind, px, py) => {
            if (!this.enabled[kind]) {
                return;
            }

            const d = Math.hypot(px - x, py - y);

            if (d > tolerance) {
                return;
            }

            // Rank first, distance only to break a tie WITHIN a rank. This is
            // the whole behaviour: a corner beats an edge that passes nearer.
            if (best === null || RANK[kind] < RANK[best.kind]
                || (RANK[kind] === RANK[best.kind] && d < best.distance)) {
                best = { kind, x: px, y: py, distance: d };
            }
        };

        for (let v = from; v < to; v++) {
            consider('vertex', coords[v * 2], coords[v * 2 + 1]);
        }

        for (let v = from; v < to - 1; v++) {
            const ax = coords[v * 2];
            const ay = coords[v * 2 + 1];
            const bx = coords[v * 2 + 2];
            const by = coords[v * 2 + 3];

            consider('midpoint', (ax + bx) / 2, (ay + by) / 2);

            const dx = bx - ax;
            const dy = by - ay;
            const lengthSq = dx * dx + dy * dy;

            if (lengthSq === 0) {
                continue;
            }

            // Clamped, so the answer is a point ON the segment rather than on
            // the infinite line through it — otherwise a distant edge whose
            // line passes nearby would capture the pointer.
            const t = Math.max(0, Math.min(1, ((x - ax) * dx + (y - ay) * dy) / lengthSq));

            consider('edge', ax + t * dx, ay + t * dy);
        }

        return best;
    }
}
