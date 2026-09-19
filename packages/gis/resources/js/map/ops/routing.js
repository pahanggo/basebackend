/**
 * Where a constructive operation should run, without loading the code that
 * runs it.
 *
 * **Deliberately free of Turf imports.** The toolbar has to know which
 * operations to offer before the user has asked for any of them, and
 * `@turf/buffer` alone drags in a polygon-clipping library: importing it here
 * put 95 kB gzipped into the initial bundle to answer a question about which
 * buttons to draw. The heavy half lives in `index.js` and is loaded on the
 * click that needs it.
 */

/**
 * Operations that always go to the server, whatever the vertex count.
 *
 * - **`makeValid`** because Turf has no equivalent.
 * - **`buffer`** because Turf's is not geodesic, and measurement is the reason
 *   rather than principle. Turf buffers in DEGREE space, so a 250 m buffer at
 *   Kuantan comes back an ellipse: 250.28 m north-south and 248.62 m
 *   east-west, enclosing 0.48% less than a circle of that radius. GEOS through
 *   a UTM projection at 32 quadrant segments gives 0.047%. The two therefore
 *   disagree by about 0.43%, and section 11 budgets 0.1% — which is the whole
 *   point of the budget: where the work runs must not be visible in the
 *   answer.
 *
 * Everything else stays on the client below the limit, and the measurements
 * say that is safe: intersection, union, difference and convex hull agree with
 * the server to seven significant figures, because they are topological rather
 * than metric and a degree is as good a unit as a metre for deciding which
 * side of an edge a point is on.
 */
export const SERVER_ONLY = new Set(['makeValid', 'buffer']);

/** Count every position in a GeoJSON geometry, holes and parts included. */
export function countVertices(geometry) {
    let total = 0;

    const walk = (coordinates) => {
        if (coordinates.length === 0) {
            return;
        }

        if (typeof coordinates[0] === 'number') {
            total += 1;

            return;
        }

        coordinates.forEach(walk);
    };

    walk(geometry.coordinates ?? []);

    return total;
}

/**
 * Where an operation should run.
 *
 * @returns {'client'|'server'} and never 'either' — a caller that has to
 *          choose is a caller that will choose differently somewhere else.
 */
export function route(op, geometries, capabilities) {
    if (SERVER_ONLY.has(op)) {
        // Still 'server' when GEOS is absent, so the request is refused with
        // the reason rather than answered wrongly by a fallback nobody asked
        // for. `available()` is what stops it being offered.
        return 'server';
    }

    if (!capabilities.geos) {
        // No server help available, but these operations do not need it.
        return 'client';
    }

    const vertices = geometries.reduce((sum, g) => sum + countVertices(g), 0);

    return vertices > (capabilities.inlineOpVertexLimit ?? 20000) ? 'server' : 'client';
}

/**
 * Whether an operation can be offered at all here.
 *
 * A control the user can press that cannot complete is worse than one that is
 * not there, so the toolbar asks this before drawing a button.
 */
export function available(op, capabilities) {
    return capabilities.geos || !SERVER_ONLY.has(op);
}
