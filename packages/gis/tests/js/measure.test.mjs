/**
 * Geodesic distance, bearing and area.
 *
 * The reference values are MySQL's, because agreeing with MySQL is the actual
 * requirement: the live readout while drawing is computed here and the
 * `area_m2` stored against the feature is computed there, and specification
 * section 11 budgets the disagreement at 0.1%. A figure that is defensible in
 * isolation but 0.4% away from what gets stored is the wrong figure.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    distance, bearing, finalBearing, length, ringArea, polygonArea, destination, inverse,
} from '../../resources/js/lib/measure.js';

/** Assert two numbers agree to within a percentage. */
function within(actual, expected, percent, message) {
    const off = Math.abs(actual - expected) / Math.abs(expected) * 100;

    assert.ok(off <= percent, `${message}: ${actual} is ${off.toFixed(4)}% from ${expected}`);
}

test('distance matches published WGS84 values', () => {
    // One degree of latitude at the equator, and one of longitude. These are
    // the two figures every geodesy reference lists, so they catch an
    // ellipsoid constant typed wrong.
    within(distance([0, 0], [0, 1]), 110574.39, 0.001, 'one degree of latitude');
    within(distance([0, 0], [1, 0]), 111319.49, 0.001, 'one degree of longitude');
});

test('a degree of longitude shrinks with latitude, a degree of latitude does not', () => {
    // The thing a spherical model gets wrong, and the reason Vincenty is here.
    const lonAtEquator = distance([0, 0], [1, 0]);
    const lonAt60 = distance([0, 60], [1, 60]);

    // Not exactly a half: cos(60) is the SPHERICAL answer, and the ellipsoid's
    // parallel radius at 60 degrees is a little more than half its equatorial
    // one. 0.50126 is the right number, and a test asserting 0.5 exactly would
    // be asserting the approximation this module exists to avoid.
    within(lonAt60 / lonAtEquator, 0.50126, 0.01, 'longitude at 60 degrees');

    const latLow = distance([0, 0], [0, 1]);
    const latHigh = distance([0, 60], [0, 61]);

    // Latitude degrees GROW toward the poles on an oblate ellipsoid — slightly.
    assert.ok(latHigh > latLow, 'a degree of latitude is longer at 60 than at 0');
    within(latHigh / latLow, 1.007, 0.2, 'the growth is under a percent');
});

test('coincident points are zero apart, not NaN', () => {
    assert.equal(distance([103.32, 3.8], [103.32, 3.8]), 0);
});

test('antipodal points return a number rather than hanging', () => {
    // Vincenty famously fails to converge here. The fallback is wrong by a
    // fraction of a percent; a loop would cost the tab.
    const d = distance([0, 0], [180, 0]);

    assert.ok(Number.isFinite(d) && d > 19_000_000, `got ${d}`);
});

test('bearings are true, clockwise from north', () => {
    assert.equal(Math.round(bearing([0, 0], [0, 1])), 0);
    assert.equal(Math.round(bearing([0, 0], [1, 0])), 90);
    assert.equal(Math.round(bearing([0, 0], [0, -1])), 180);
    assert.equal(Math.round(bearing([0, 0], [-1, 0])), 270);
});

test('a great circle arrives on a different bearing than it left', () => {
    // Which is why the readout names the forward and back azimuth separately
    // rather than one figure and its opposite.
    const from = [0, 40];
    const to = [80, 40];

    assert.notEqual(Math.round(bearing(from, to)), Math.round((finalBearing(from, to) + 180) % 360));
});

test('length is the sum of its segments', () => {
    const path = [[103.30, 3.80], [103.31, 3.80], [103.31, 3.81]];

    within(
        length(path),
        distance(path[0], path[1]) + distance(path[1], path[2]),
        1e-9,
        'length',
    );
});

test('area agrees with MySQL ST_Area to well inside the 0.1% budget', () => {
    // Measured against `ST_Area(..., 4326)` on this deployment. The naive
    // spherical version was 0.44% high on every one of these.
    const cases = [
        [[[103.3200, 3.8000], [103.3210, 3.8000], [103.3210, 3.8010], [103.3200, 3.8010], [103.3200, 3.8000]], 12282.786],
        [[[103.300, 3.790], [103.340, 3.790], [103.340, 3.820], [103.300, 3.820], [103.300, 3.790]], 14739196.224],
        [[[102.50, 3.00], [104.00, 3.00], [104.00, 4.50], [102.50, 4.50], [102.50, 3.00]], 27638497134.743],
        [[[101.00, 0.10], [101.50, 0.10], [101.50, 0.60], [101.00, 0.60], [101.00, 0.10]], 3077222300.941],
    ];

    for (const [ring, expected] of cases) {
        within(ringArea(ring), expected, 0.01, 'area');
    }
});

test('a ring measures the same open or closed', () => {
    const open = [[103.30, 3.80], [103.31, 3.80], [103.31, 3.81], [103.30, 3.81]];

    within(ringArea(open), ringArea([...open, open[0]]), 1e-9, 'closure');
});

test('winding does not change the area', () => {
    // Winding is normalised on save, so its direction is not something the
    // readout should be reporting on.
    const ring = [[103.30, 3.80], [103.31, 3.80], [103.31, 3.81], [103.30, 3.81]];

    within(ringArea(ring), ringArea([...ring].reverse()), 1e-9, 'reversed');
});

test('a ring with fewer than three corners encloses nothing', () => {
    assert.equal(ringArea([[0, 0], [1, 1]]), 0);
    assert.equal(ringArea([]), 0);
});

test('holes are subtracted from the exterior', () => {
    const exterior = [[103.30, 3.80], [103.40, 3.80], [103.40, 3.90], [103.30, 3.90], [103.30, 3.80]];
    const hole = [[103.32, 3.82], [103.34, 3.82], [103.34, 3.84], [103.32, 3.84], [103.32, 3.82]];

    within(
        polygonArea([exterior, hole]),
        ringArea(exterior) - ringArea(hole),
        1e-9,
        'with a hole',
    );

    assert.equal(polygonArea([]), 0);
});

// --------------------------------------------------- numeric entry round trip

test('a point placed by bearing and distance measures back to those numbers', () => {
    // This is what numeric entry does, and the property it lives or dies by: a
    // survey drawing whose sides do not measure back to the figures that made
    // them is not a survey drawing.
    const from = [103.3260, 3.8077];
    let worstDistance = 0;
    let worstBearing = 0;

    for (const b of [0, 37, 63.25, 90, 145, 180, 233, 270, 318, 359.5]) {
        for (const d of [10, 142.7, 1000, 25000]) {
            const to = destination(from, b, d);

            worstDistance = Math.max(worstDistance, Math.abs(distance(from, to) - d));

            let off = Math.abs(bearing(from, to) - b);

            if (off > 180) {
                off = 360 - off;
            }

            worstBearing = Math.max(worstBearing, off);
        }
    }

    assert.ok(worstDistance < 1e-4, `distance drifted ${worstDistance} m`);
    assert.ok(worstBearing < 1e-4, `bearing drifted ${worstBearing} degrees`);
});

test('bearing and distance come from ONE solution, not two formulas', () => {
    // They disagreed by 0.15 degrees when the distance was ellipsoidal and the
    // bearing a great circle. Nothing failed; the drawing was just wrong.
    const from = [103.30, 3.80];
    const to = [103.45, 3.95];
    const solution = inverse(from, to);

    assert.equal(solution.distance, distance(from, to));
    assert.equal(solution.initialBearing, bearing(from, to));
    assert.equal(solution.finalBearing, finalBearing(from, to));
});

test('travelling zero metres stays put', () => {
    const from = [103.326, 3.8077];

    assert.deepEqual(destination(from, 45, 0), from);
});
