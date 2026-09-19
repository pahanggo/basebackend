/**
 * Constructive geometry on the client, and the decision about when not to.
 *
 * **Turf for small inputs, the server for large ones**, routed from
 * `capabilities` rather than a constant — so a deployment without the GEOS
 * binary, or one that wants a different threshold, changes configuration
 * instead of shipping different JavaScript (specification §5).
 *
 * The threshold is about latency, not correctness. Both paths are geodesic and
 * both agree to within the 0.1% section 11 budgets; what differs is that
 * buffering a fifty-thousand-vertex boundary in the main thread stops the tab,
 * and doing it over a round trip does not.
 *
 * None of this is on a hot path. It runs when a user clicks a button, never
 * per pan, which is what makes a round trip acceptable at all.
 */

import buffer from '@turf/buffer';
import union from '@turf/union';
import difference from '@turf/difference';
import intersect from '@turf/intersect';
import simplify from '@turf/simplify';
import convex from '@turf/convex';
import centroid from '@turf/centroid';
import pointOnFeature from '@turf/point-on-feature';
import { featureCollection, feature as asFeature } from '@turf/helpers';
import { route, available, countVertices, SERVER_ONLY } from './routing.js';

// Re-exported so a caller that has already paid for this module does not need
// a second import for the cheap half.
export { route, available, countVertices, SERVER_ONLY };

/**
 * Run an operation in Turf.
 *
 * Turf works in GeoJSON features, so everything is wrapped on the way in and
 * unwrapped on the way out. A null result means the operation produced nothing
 * — two shapes that do not overlap have no intersection — which is an answer,
 * not a failure, and is reported the same way the endpoint reports it.
 */
export function runLocally(op, geometries, options = {}) {
    const features = geometries.map((g) => asFeature(g, {}));
    const [a, b] = features;

    const result = {
        // Turf's buffer takes kilometres by default; metres is what every
        // other distance in this package is in, so it is converted here rather
        // than at each call site.
        // Kept for the harness and for a deployment with no GEOS at all, but
        // `SERVER_ONLY` means `run` never reaches it: see the note there.
        buffer: () => buffer(a, options.metres ?? 0, { units: 'meters', steps: options.steps ?? 32 }),
        union: () => union(featureCollection(features)),
        difference: () => difference(featureCollection([a, b])),
        intersection: () => intersect(featureCollection([a, b])),
        convexHull: () => convex(a),
        centroid: () => centroid(a),
        pointOnSurface: () => pointOnFeature(a),
        simplify: () => simplify(a, {
            // Turf's tolerance is in degrees. A user asking to simplify to
            // five metres means five metres, so it is converted at the
            // geometry's own latitude — a degree of longitude is shorter the
            // further from the equator you are.
            tolerance: metresToDegrees(options.toleranceMetres ?? 1, latitudeOf(geometries[0])),
            highQuality: true,
        }),
    }[op]?.();

    return result ? result.geometry : null;
}

/**
 * Run an operation on the server.
 *
 * The result comes back as base64 WKB — the same encoding a command carries
 * geometry in — so it can be handed straight to `feature.create` without a
 * second conversion.
 */
export async function runRemotely(op, geometries, { apiBase, csrfToken, ...options }) {
    const response = await fetch(`${apiBase}/geometry/ops`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({
            op,
            geom: JSON.stringify(geometries[0]),
            other: geometries[1] ? JSON.stringify(geometries[1]) : undefined,
            geomEncoding: 'geojson',
            ...options,
        }),
    });

    const body = await response.json();

    if (!response.ok) {
        throw new Error(body.detail ?? `The ${op} operation failed.`);
    }

    return body.empty ? null : { wkb: body.geom };
}

/**
 * One entry point, whichever side does the work.
 *
 * Returns `{ geometry }` for a client result, `{ wkb }` for a server one, or
 * null for an empty result. The shapes differ because the encodings do, and
 * pretending otherwise would mean converting one of them for no reason.
 */
export async function run(op, geometries, { capabilities, ...options }) {
    if (route(op, geometries, capabilities) === 'client') {
        const geometry = runLocally(op, geometries, options);

        return geometry ? { geometry } : null;
    }

    return runRemotely(op, geometries, options);
}

/** A rough metres-to-degrees at a latitude, for Turf's degree tolerances. */
function metresToDegrees(metres, latitude) {
    // One degree of latitude is about 111,320 m everywhere; longitude shrinks
    // by the cosine. The smaller of the two is the safe one for a tolerance,
    // because it errs toward simplifying less.
    return metres / (111_320 * Math.max(0.1, Math.cos((latitude * Math.PI) / 180)));
}

/** A representative latitude for a geometry, for the conversion above. */
function latitudeOf(geometry) {
    let found = 0;

    const walk = (coordinates) => {
        if (coordinates.length === 0 || found !== 0) {
            return;
        }

        if (typeof coordinates[0] === 'number') {
            found = coordinates[1];

            return;
        }

        coordinates.forEach(walk);
    };

    walk(geometry.coordinates ?? []);

    return found;
}
