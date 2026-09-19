/**
 * What a measurement IS, separately from how it is taken or drawn.
 *
 * Every function here is pure and takes coordinates, so the same arithmetic
 * that a pointer gesture produces can be asserted against the server's
 * geodesic answer without a map, a canvas or a browser in the way — which is
 * exactly what S10's gate asks for (specification §11).
 *
 * **The invariant this module exists to hold**: for a stored `distance`, the
 * value is the geodesic length of the stored geometry. Nothing may store a
 * value the geometry does not account for, because a measurement whose number
 * and whose line disagree is worse than one of the two alone — it looks
 * checkable and is not.
 *
 * That invariant is what decides how a DIAMETER is stored. The gesture is
 * centre-then-edge, but the saved line is the full chord through the centre,
 * so its length is the diameter. Storing centre-to-edge with a doubled value
 * would have been half a line and a number nobody could verify from it.
 */

import { distance, bearing, finalBearing, length, polygonArea, destination } from '../../lib/measure.js';
import { formatDistance, formatArea, formatBearing } from '../../lib/units.js';

/** Every measurement tool, in the order the toolbar shows them. */
export const MEASURE_TOOLS = ['distance', 'area', 'radius', 'diameter', 'bearing', 'info'];

/**
 * Tool to stored kind.
 *
 * Three kinds, six tools. `gis_measurements.kind` is an enum of what the value
 * MEANS — a length, an area, an azimuth — and radius and diameter are both
 * lengths. Which tool took it is recorded separately, because it changes how
 * the annotation is read aloud but not what the number is.
 */
export const TOOL_KIND = {
    distance: 'distance',
    area: 'area',
    radius: 'distance',
    diameter: 'distance',
    bearing: 'bearing',
};

/** How many points each tool needs before it has anything, and at most. */
export const TOOL_POINTS = {
    distance: [2, Infinity],
    area: [3, Infinity],
    radius: [2, 2],
    diameter: [2, 2],
    bearing: [2, 2],
};

/**
 * The geometry a tool's points describe, or null if there are not enough yet.
 *
 * @param {string} tool
 * @param {Array<[number, number]>} points lon-lat, in the order placed
 * @return {{type: string, coordinates: *}|null}
 */
export function geometryFor(tool, points) {
    const [minimum] = TOOL_POINTS[tool] ?? [Infinity, Infinity];

    if (!points || points.length < minimum) {
        return null;
    }

    if (tool === 'area') {
        const ring = [...points, points[0]];

        return { type: 'Polygon', coordinates: [ring] };
    }

    if (tool === 'diameter') {
        const [centre, edge] = points;
        const radius = distance(centre, edge);

        if (radius <= 0) {
            return null;
        }

        // The far end of the same line, through the centre. Computed by
        // travelling the other way rather than by reflecting the coordinates:
        // on an ellipsoid the reflection of a point is not the point the same
        // distance away on the opposite bearing, and at this package's
        // latitudes the difference is small enough to look correct and wrong
        // enough to fail the 0.1% gate.
        const back = destination(centre, bearing(centre, edge) + 180, radius);

        return { type: 'LineString', coordinates: [back, edge] };
    }

    const [first, second] = points;

    return {
        type: 'LineString',
        coordinates: tool === 'distance' ? points : [first, second],
    };
}

/**
 * The number a geometry is worth, given what kind of measurement it is.
 *
 * Derived from the geometry rather than accumulated while drawing, so the
 * stored value and the stored line cannot drift apart — and so a measurement
 * whose geometry is later edited can simply be re-measured.
 */
export function valueFor(kind, geometry) {
    if (!geometry) {
        return 0;
    }

    if (kind === 'area') {
        return polygonArea(geometry.coordinates);
    }

    const coordinates = geometry.coordinates;

    if (kind === 'bearing') {
        return bearing(coordinates[0], coordinates[coordinates.length - 1]);
    }

    return length(coordinates);
}

/**
 * The back azimuth, for the bearing tool's second line of readout.
 *
 * Not simply the forward bearing plus 180°. On an ellipsoid a geodesic's
 * azimuth changes along its length, and over a long line the difference is
 * degrees, not rounding — which is the entire reason a title document quotes
 * both ends.
 */
export function backBearing(geometry) {
    const coordinates = geometry?.coordinates ?? [];

    return coordinates.length >= 2
        ? finalBearing(coordinates[0], coordinates[coordinates.length - 1])
        : 0;
}

/**
 * The label a measurement carries on the map.
 *
 * A measurement the user has named shows its name; an unnamed one shows its
 * number. Never both — the annotation sits over a map someone is reading, and
 * two lines of chrome per measurement is how a map becomes unreadable at five
 * of them.
 */
export function describe(measurement, preferences = {}) {
    if (measurement.label) {
        return measurement.label;
    }

    return formatValue(measurement.kind, measurement.value, preferences);
}

/** Just the number, in the reader's units. */
export function formatValue(kind, value, preferences = {}) {
    if (kind === 'area') {
        return formatArea(value, preferences);
    }

    if (kind === 'bearing') {
        return formatBearing(value);
    }

    return formatDistance(value, preferences);
}

/**
 * Where the label goes: the middle of the thing measured.
 *
 * The mean of the vertices, which for the two-point tools is the midpoint and
 * for a ring is close enough to the middle to read as belonging to it. A true
 * centroid would sit outside a C-shaped parcel, which is a worse answer for a
 * label than a slightly off-centre one.
 */
export function anchorOf(geometry) {
    const points = geometry?.type === 'Polygon'
        // The closing vertex repeats the first and would weight it twice.
        ? (geometry.coordinates[0] ?? []).slice(0, -1)
        : (geometry?.coordinates ?? []);

    if (points.length === 0) {
        return null;
    }

    let lng = 0;
    let lat = 0;

    for (const point of points) {
        lng += point[0];
        lat += point[1];
    }

    return [lng / points.length, lat / points.length];
}

/** Every vertex of a measurement, for painting and for hit testing. */
export function outlineOf(geometry) {
    if (!geometry) {
        return [];
    }

    return geometry.type === 'Polygon' ? (geometry.coordinates[0] ?? []) : geometry.coordinates;
}
