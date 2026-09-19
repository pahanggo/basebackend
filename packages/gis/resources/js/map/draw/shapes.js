/**
 * Turning a gesture or a set of numbers into a ring of coordinates.
 *
 * Everything here is pure: lon-lat in, lon-lat out, no map and no pointer. That
 * is what lets the pointer path and the numeric path produce byte-identical
 * geometry — the specification requires both to be first-class, and the only
 * way to be sure they agree is for both to end up in the same function
 * (section 9).
 */

import { destination, distance, bearing } from '../../lib/measure.js';

/** How many segments approximate a circle. */
export const CIRCLE_SEGMENTS = 72;

/**
 * A rectangle, as a closed ring.
 *
 * **Built by walking bearings from a corner, not by combining longitudes and
 * latitudes.** A rectangle made from two corner coordinates is only a rectangle
 * in degrees: its north edge is shorter on the ground than its south edge,
 * because a degree of longitude shrinks with latitude. Over a city block that
 * is centimetres, and over a district it is metres — either way the width the
 * user typed is not the width they get.
 *
 * @param {number[]} origin [longitude, latitude] of one corner
 * @param {number} width metres along the rotation bearing
 * @param {number} height metres perpendicular to it
 * @param {number} rotation degrees clockwise from true north for the width axis
 */
export function rectangleRing(origin, width, height, rotation = 90) {
    const along = rotation;
    const across = (rotation + 90) % 360;

    const a = origin;
    const b = destination(a, along, width);
    const c = destination(b, across, height);
    const d = destination(a, across, height);

    return [a, b, c, d, a];
}

/**
 * A rectangle from two dragged corners, axis-aligned.
 *
 * The drag gives two opposite corners in degrees, so this measures the sides
 * that implies and then rebuilds the shape properly through `rectangleRing` —
 * which is what keeps a dragged rectangle and a typed one the same object.
 */
export function rectangleFromDrag(start, end) {
    const west = Math.min(start[0], end[0]);
    const east = Math.max(start[0], end[0]);
    const south = Math.min(start[1], end[1]);
    const north = Math.max(start[1], end[1]);

    const origin = [west, south];

    return rectangleRing(
        origin,
        distance(origin, [east, south]),
        distance(origin, [west, north]),
        90,
    );
}

/**
 * A circle of a given radius, as a closed ring.
 *
 * Every vertex is placed by travelling the radius along a bearing, so the
 * result is a circle on the ellipsoid — equidistant on the ground — rather
 * than an ellipse in degrees that only looks circular near the equator.
 *
 * **A circle is stored as a polygon.** MySQL has no circle type and `geom` is
 * one column, so there is nowhere else for it to go. The consequence is that
 * the centre and radius are not recoverable exactly from what is stored, only
 * to within the segment count.
 */
export function circleRing(centre, radiusMetres, segments = CIRCLE_SEGMENTS) {
    const ring = [];

    for (let i = 0; i < segments; i++) {
        ring.push(destination(centre, (i * 360) / segments, radiusMetres));
    }

    ring.push(ring[0]);

    return ring;
}

/** The radius a centre-to-edge drag implies, in metres. */
export function radiusFromDrag(centre, edge) {
    return distance(centre, edge);
}

/**
 * A point constrained to the nearest 15° increment from an anchor.
 *
 * What `Shift` does while drawing. The constraint is applied to the BEARING
 * and the distance is kept, so the constrained point sits where the pointer
 * is, turned onto the nearest allowed line — rather than where the pointer
 * would be if it had travelled a different distance.
 */
export function constrainToAngle(from, to, step = 15) {
    const solution = bearing(from, to);
    const snapped = Math.round(solution / step) * step;

    return destination(from, snapped, distance(from, to));
}

/**
 * Douglas-Peucker, for a freehand stroke.
 *
 * **This is the one place simplification is allowed in this package, and it is
 * not level of detail.** It reduces a stroke as it is drawn, once, before it
 * becomes a feature — a pointer emits a sample every few milliseconds and
 * nobody means to place four hundred vertices along one kerb line. What is
 * banned is simplifying stored geometry for display, which changes what a
 * feature IS depending on where you are looking at it from.
 *
 * Planar on purpose: the tolerance is in degrees because the stroke is a
 * gesture, and the alternative is a geodesic distance per point per recursion.
 *
 * @param {Array<number[]>} points
 * @param {number} tolerance in degrees
 */
export function simplify(points, tolerance) {
    if (points.length <= 2 || tolerance <= 0) {
        return points;
    }

    const keep = new Uint8Array(points.length);

    keep[0] = 1;
    keep[points.length - 1] = 1;

    // Iterative rather than recursive: a freehand stroke can be thousands of
    // samples, and the worst case for the recursive form is a stack as deep as
    // the stroke is long.
    const stack = [[0, points.length - 1]];

    while (stack.length > 0) {
        const [first, last] = stack.pop();
        let furthest = -1;
        let worst = tolerance;

        for (let i = first + 1; i < last; i++) {
            const d = perpendicularDistance(points[i], points[first], points[last]);

            if (d > worst) {
                worst = d;
                furthest = i;
            }
        }

        if (furthest !== -1) {
            keep[furthest] = 1;
            stack.push([first, furthest], [furthest, last]);
        }
    }

    return points.filter((_, i) => keep[i] === 1);
}

/** Distance from a point to the segment a-b, in degrees. */
function perpendicularDistance(point, a, b) {
    let dx = b[0] - a[0];
    let dy = b[1] - a[1];

    if (dx === 0 && dy === 0) {
        return Math.hypot(point[0] - a[0], point[1] - a[1]);
    }

    // Projection parameter, clamped so the distance is to the SEGMENT rather
    // than to the infinite line through it.
    const t = Math.max(0, Math.min(1,
        ((point[0] - a[0]) * dx + (point[1] - a[1]) * dy) / (dx * dx + dy * dy)));

    dx = a[0] + t * dx - point[0];
    dy = a[1] + t * dy - point[1];

    return Math.hypot(dx, dy);
}
