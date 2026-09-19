/**
 * Geodesic distance, bearing and area, in metres on the WGS84 ellipsoid.
 *
 * **These have to agree with the server, not merely be reasonable.** The live
 * readout while drawing is computed here; the `area_m2` stored against the
 * feature is computed by MySQL's `ST_Area` on SRID 4326, which is ellipsoidal.
 * Specification section 11 budgets the disagreement at 0.1%, and a spherical
 * approximation does not meet it — which is why distance is Vincenty rather
 * than haversine, and why the area uses the authalic radius rather than the
 * mean one.
 *
 * Shared with S10's saved measurements, which is the other consumer of exactly
 * these numbers.
 */

/** WGS84, the reference system everything in this package is stored in. */
const A = 6378137.0;                 // semi-major axis, metres
const F = 1 / 298.257223563;         // flattening
const B = A * (1 - F);               // semi-minor axis

/**
 * The radius of the sphere with the same surface area as the WGS84 ellipsoid.
 *
 * Used for area, and it is the authalic radius specifically rather than the
 * mean radius (6371008.8) for the reason the name says: area is what has to be
 * preserved. The difference between the two is 1.6 m in the radius and about
 * 0.05% in an area, which is half the budget spent on a constant.
 */
const AUTHALIC_RADIUS = 6371007.181;

/** First eccentricity, and its square, from the flattening. */
const E2 = F * (2 - F);
const E = Math.sqrt(E2);

const RAD = Math.PI / 180;

/**
 * The authalic latitude of a geodetic one, in radians.
 *
 * **This is what makes the area ellipsoidal rather than merely spherical.**
 * Projecting each vertex onto the equal-area sphere before applying spherical
 * excess is exact for area, where using the geodetic latitude directly is not:
 * measured against MySQL's `ST_Area` on SRID 4326, the naive version was 0.44%
 * high at every scale from a house plot to a district — consistently, because
 * near the equator the ellipsoid's local area element is about that much
 * smaller than the equal-area sphere's. Section 11 budgets 0.1%, so 0.44% is
 * not a rounding difference, it is the wrong answer four times over.
 */
function authalicLatitude(latitude) {
    const sinPhi = Math.sin(latitude * RAD);
    const eSin = E * sinPhi;

    // Snyder's q(phi): twice the area of the zone from the equator to phi,
    // divided by the semi-major axis squared.
    const q = (1 - E2) * (
        sinPhi / (1 - eSin * eSin)
        - (1 / (2 * E)) * Math.log((1 - eSin) / (1 + eSin))
    );

    // q at the pole, which normalises q into a sine.
    const qp = (1 - E2) * (
        1 / (1 - E2)
        - (1 / (2 * E)) * Math.log((1 - E) / (1 + E))
    );

    return Math.asin(Math.max(-1, Math.min(1, q / qp)));
}

/**
 * Distance, initial bearing and final bearing in one solution.
 *
 * Vincenty's inverse formula. All three come out of the same iteration, and
 * that is deliberate: computing the distance here and the bearing from a
 * great-circle formula elsewhere makes them disagree with each other and with
 * `destination`. It is iterative and it does not always converge —
 * near-antipodal points are the classic failure — so it gives up after a fixed
 * number of passes and falls back to the spherical answer rather than looping
 * or returning `NaN`. Nothing this tool measures is antipodal, but a fallback
 * costs four lines and a hang costs the tab.
 *
 * @param {number[]} from [longitude, latitude]
 * @param {number[]} to [longitude, latitude]
 */
export function inverse(from, to) {
    const L = (to[0] - from[0]) * RAD;
    const U1 = Math.atan((1 - F) * Math.tan(from[1] * RAD));
    const U2 = Math.atan((1 - F) * Math.tan(to[1] * RAD));
    const sinU1 = Math.sin(U1);
    const cosU1 = Math.cos(U1);
    const sinU2 = Math.sin(U2);
    const cosU2 = Math.cos(U2);

    let lambda = L;
    let sinLambda = 0;
    let cosLambda = 1;
    let sinSigma = 0;
    let cosSigma = 0;
    let sigma = 0;
    let cos2SigmaM = 0;
    let cosSqAlpha = 0;

    for (let i = 0; i < 100; i++) {
        sinLambda = Math.sin(lambda);
        cosLambda = Math.cos(lambda);

        sinSigma = Math.sqrt(
            (cosU2 * sinLambda) ** 2
            + (cosU1 * sinU2 - sinU1 * cosU2 * cosLambda) ** 2,
        );

        if (sinSigma === 0) {
            return { distance: 0, initialBearing: 0, finalBearing: 0 };
        }

        cosSigma = sinU1 * sinU2 + cosU1 * cosU2 * cosLambda;
        sigma = Math.atan2(sinSigma, cosSigma);

        const sinAlpha = (cosU1 * cosU2 * sinLambda) / sinSigma;

        cosSqAlpha = 1 - sinAlpha * sinAlpha;
        cos2SigmaM = cosSqAlpha === 0 ? 0 : cosSigma - (2 * sinU1 * sinU2) / cosSqAlpha;

        const C = (F / 16) * cosSqAlpha * (4 + F * (4 - 3 * cosSqAlpha));
        const previous = lambda;

        lambda = L + (1 - C) * F * sinAlpha
            * (sigma + C * sinSigma * (cos2SigmaM + C * cosSigma * (-1 + 2 * cos2SigmaM ** 2)));

        if (Math.abs(lambda - previous) < 1e-12) {
            const uSq = cosSqAlpha * (A * A - B * B) / (B * B);
            const k1 = (Math.sqrt(1 + uSq) - 1) / (Math.sqrt(1 + uSq) + 1);
            const AA = (1 + (k1 * k1) / 4) / (1 - k1);
            const BB = k1 * (1 - (3 * k1 * k1) / 8);

            const deltaSigma = BB * sinSigma * (cos2SigmaM + (BB / 4) * (
                cosSigma * (-1 + 2 * cos2SigmaM ** 2)
                - (BB / 6) * cos2SigmaM * (-3 + 4 * sinSigma ** 2) * (-3 + 4 * cos2SigmaM ** 2)
            ));

            // Both azimuths come out of the same solution, which is the whole
            // reason they are computed here rather than separately. A
            // great-circle bearing beside an ellipsoidal distance disagrees
            // with `destination` by about 0.15 degrees — measured — and a
            // survey drawing whose sides do not measure back to the numbers
            // that produced them is not a survey drawing.
            return {
                distance: B * AA * (sigma - deltaSigma),
                initialBearing: (Math.atan2(
                    cosU2 * sinLambda,
                    cosU1 * sinU2 - sinU1 * cosU2 * cosLambda,
                ) / RAD + 360) % 360,
                finalBearing: (Math.atan2(
                    cosU1 * sinLambda,
                    -sinU1 * cosU2 + cosU1 * sinU2 * cosLambda,
                ) / RAD + 360) % 360,
            };
        }
    }

    // Did not converge, which Vincenty's inverse famously does not for
    // near-antipodal points. Spherical is wrong by a fraction of a percent and
    // is an answer; a loop or a NaN is not.
    return {
        distance: haversine(from, to),
        initialBearing: sphericalBearing(from, to),
        finalBearing: (sphericalBearing(to, from) + 180) % 360,
    };
}

/** Distance in metres between two lon-lat points, on the ellipsoid. */
export function distance(from, to) {
    return inverse(from, to).distance;
}

/** The spherical fallback, and the only place a sphere is used for distance. */
function haversine(from, to) {
    const dLat = (to[1] - from[1]) * RAD;
    const dLon = (to[0] - from[0]) * RAD;
    const a = Math.sin(dLat / 2) ** 2
        + Math.cos(from[1] * RAD) * Math.cos(to[1] * RAD) * Math.sin(dLon / 2) ** 2;

    return 2 * AUTHALIC_RADIUS * Math.asin(Math.min(1, Math.sqrt(a)));
}

/**
 * Initial bearing from one point to another, in degrees clockwise from TRUE
 * north.
 *
 * True, never magnetic. Magnetic bearing was dropped from this package
 * deliberately: it needs a declination model that goes stale, and a survey
 * drawing that is silently five years out of date is worse than one that never
 * claimed to be magnetic.
 *
 * "Initial" matters over long distances — a great circle changes bearing as it
 * goes, so the figure at the far end differs. `finalBearing` is the other end.
 */
export function bearing(from, to) {
    return inverse(from, to).initialBearing;
}

/**
 * The bearing as it is on arrival.
 *
 * Read off the same solution as the initial one rather than derived by
 * reversing the pair, because on an ellipsoid those are not the same number.
 */
export function finalBearing(from, to) {
    return inverse(from, to).finalBearing;
}

/** The great-circle bearing, used only where Vincenty has given up. */
function sphericalBearing(from, to) {
    const phi1 = from[1] * RAD;
    const phi2 = to[1] * RAD;
    const dLambda = (to[0] - from[0]) * RAD;

    const y = Math.sin(dLambda) * Math.cos(phi2);
    const x = Math.cos(phi1) * Math.sin(phi2)
        - Math.sin(phi1) * Math.cos(phi2) * Math.cos(dLambda);

    return (Math.atan2(y, x) / RAD + 360) % 360;
}

/** Total length of a lon-lat path, in metres. */
export function length(points) {
    let total = 0;

    for (let i = 1; i < points.length; i++) {
        total += distance(points[i - 1], points[i]);
    }

    return total;
}

/**
 * The area enclosed by a ring, in square metres.
 *
 * Spherical excess over AUTHALIC latitudes on the equal-area sphere, which is
 * exact for area on the ellipsoid and agrees with `ST_Area` on SRID 4326 to
 * better than a part in a million. Using geodetic latitudes directly, which is
 * the version most references give, is 0.44% high here. The ring is treated as
 * closed whether or not its last point repeats its first.
 *
 * Returns an unsigned area: winding is normalised on save (section 9), so a
 * ring's direction is not information the user is asking about here.
 */
export function ringArea(points) {
    const ring = points.length > 2
        && points[0][0] === points[points.length - 1][0]
        && points[0][1] === points[points.length - 1][1]
        ? points.slice(0, -1)
        : points;

    if (ring.length < 3) {
        return 0;
    }

    let total = 0;

    for (let i = 0; i < ring.length; i++) {
        const [lon1, lat1] = ring[i];
        const [lon2, lat2] = ring[(i + 1) % ring.length];

        // Spherical excess, but over authalic latitudes — see above.
        total += (lon2 - lon1) * RAD
            * (2 + Math.sin(authalicLatitude(lat1)) + Math.sin(authalicLatitude(lat2)));
    }

    return Math.abs((total * AUTHALIC_RADIUS * AUTHALIC_RADIUS) / 2);
}

/**
 * A polygon's area: its exterior ring, less its holes.
 *
 * @param {Array<Array<number[]>>} rings exterior first, then holes
 */
export function polygonArea(rings) {
    if (rings.length === 0) {
        return 0;
    }

    return rings.slice(1).reduce((area, hole) => area - ringArea(hole), ringArea(rings[0]));
}

/**
 * The point reached by travelling a distance along a bearing.
 *
 * Vincenty's DIRECT formula, the exact inverse of `distance` and `bearing`
 * above. It has to be exact rather than close: this is what turns "142.7 m on
 * a bearing of 63°15'" into a vertex, and a survey drawing whose sides do not
 * measure back to the numbers that produced them is not a survey drawing.
 *
 * Unlike the inverse formula this one always converges, so there is no
 * fallback to justify.
 *
 * @param {number[]} from [longitude, latitude]
 * @param {number} initialBearing degrees clockwise from true north
 * @param {number} metres
 * @returns {number[]} [longitude, latitude]
 */
export function destination(from, initialBearing, metres) {
    if (metres === 0) {
        return [from[0], from[1]];
    }

    const alpha1 = initialBearing * RAD;
    const sinAlpha1 = Math.sin(alpha1);
    const cosAlpha1 = Math.cos(alpha1);

    const tanU1 = (1 - F) * Math.tan(from[1] * RAD);
    const cosU1 = 1 / Math.sqrt(1 + tanU1 * tanU1);
    const sinU1 = tanU1 * cosU1;

    const sigma1 = Math.atan2(tanU1, cosAlpha1);
    const sinAlpha = cosU1 * sinAlpha1;
    const cosSqAlpha = 1 - sinAlpha * sinAlpha;
    const uSq = (cosSqAlpha * (A * A - B * B)) / (B * B);
    const k1 = (Math.sqrt(1 + uSq) - 1) / (Math.sqrt(1 + uSq) + 1);
    const AA = (1 + (k1 * k1) / 4) / (1 - k1);
    const BB = k1 * (1 - (3 * k1 * k1) / 8);

    let sigma = metres / (B * AA);
    let sinSigma = 0;
    let cosSigma = 0;
    let cos2SigmaM = 0;

    for (let i = 0; i < 100; i++) {
        cos2SigmaM = Math.cos(2 * sigma1 + sigma);
        sinSigma = Math.sin(sigma);
        cosSigma = Math.cos(sigma);

        const deltaSigma = BB * sinSigma * (cos2SigmaM + (BB / 4) * (
            cosSigma * (-1 + 2 * cos2SigmaM ** 2)
            - (BB / 6) * cos2SigmaM * (-3 + 4 * sinSigma ** 2) * (-3 + 4 * cos2SigmaM ** 2)
        ));

        const previous = sigma;

        sigma = metres / (B * AA) + deltaSigma;

        if (Math.abs(sigma - previous) < 1e-12) {
            break;
        }
    }

    const tmp = sinU1 * sinSigma - cosU1 * cosSigma * cosAlpha1;
    const latitude = Math.atan2(
        sinU1 * cosSigma + cosU1 * sinSigma * cosAlpha1,
        (1 - F) * Math.sqrt(sinAlpha * sinAlpha + tmp * tmp),
    );

    const lambda = Math.atan2(
        sinSigma * sinAlpha1,
        cosU1 * cosSigma - sinU1 * sinSigma * cosAlpha1,
    );

    const C = (F / 16) * cosSqAlpha * (4 + F * (4 - 3 * cosSqAlpha));
    const L = lambda - (1 - C) * F * sinAlpha
        * (sigma + C * sinSigma * (cos2SigmaM + C * cosSigma * (-1 + 2 * cos2SigmaM ** 2)));

    return [from[0] + L / RAD, latitude / RAD];
}
