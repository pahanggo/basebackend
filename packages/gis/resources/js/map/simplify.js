/**
 * Visvalingam-Whyatt simplification, for the minority case it was meant for.
 *
 * Simplification is nearly useless against this workload and it is worth being
 * explicit about why: parcels average 6.5 vertices. Reducing a quadrilateral
 * gives a triangle, then a line. Vertex count was never the cost — polygon
 * count was, and the area cull is what addresses it.
 *
 * What simplification is for is the other tail: `usages` reaches 15,709
 * vertices, and an estate spanning kilometres stays on screen at every zoom, so
 * it is drawn at every zoom. Those are the features worth thinning.
 *
 * Rather than copying coordinates, this marks which vertices survive. The
 * renderer skips the rest, so one coordinate array serves every level of
 * detail.
 */

/** Twice the area of the triangle formed by three vertices. */
function effectiveArea(coords, a, b, c) {
    return Math.abs(
        (coords[a * 2] - coords[c * 2]) * (coords[b * 2 + 1] - coords[a * 2 + 1])
        - (coords[a * 2] - coords[b * 2]) * (coords[c * 2 + 1] - coords[a * 2 + 1]),
    );
}

/**
 * Mark the vertices worth keeping in features above a vertex count.
 *
 * @param {Object} geometry the typed-array store
 * @param {number} vertexThreshold features with fewer vertices are left alone
 * @param {number} minArea drop vertices whose triangle is smaller, in square degrees
 * @returns {Uint8Array} 1 per kept vertex, 0 per dropped
 */
export function simplifyLargeFeatures(geometry, vertexThreshold, minArea = 1e-9) {
    const { coords, ringStarts, featStarts, count } = geometry;
    const keep = new Uint8Array(coords.length / 2).fill(1);

    for (let f = 0; f < count; f++) {
        const firstRing = featStarts[f];
        const lastRing = featStarts[f + 1];
        const vertices = ringStarts[lastRing] - ringStarts[firstRing];

        if (vertices < vertexThreshold) {
            continue;
        }

        for (let r = firstRing; r < lastRing; r++) {
            const start = ringStarts[r];
            const end = ringStarts[r + 1];

            // A ring needs its first and last vertex, and a polygon ring that
            // falls below four vertices is not a polygon any more — it is the
            // triangle-then-line failure this whole comment block is about. So
            // the floor is enforced rather than hoped for.
            let kept = end - start;

            for (let v = start + 1; v < end - 1 && kept > 4; v++) {
                if (effectiveArea(coords, v - 1, v, v + 1) < minArea) {
                    keep[v] = 0;
                    kept--;
                }
            }
        }
    }

    return keep;
}
