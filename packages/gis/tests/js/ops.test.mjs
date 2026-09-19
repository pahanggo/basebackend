/**
 * Where a constructive operation runs, and whether it matters.
 *
 * The session's gate is that the client and server paths agree for the same
 * input, within the 0.1% section 11 budgets. These tests pin the routing
 * decision; the agreement itself is measured in `GeometryOpsTest`, which can
 * run both sides.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { route, available, countVertices } from '../../resources/js/map/ops/routing.js';
import { runLocally } from '../../resources/js/map/ops/index.js';
import { ringArea, distance } from '../../resources/js/lib/measure.js';

const CAPS = { geos: true, inlineOpVertexLimit: 20000 };
const POINT = { type: 'Point', coordinates: [103.3260, 3.8077] };

const A = { type: 'Polygon', coordinates: [[
    [103.320, 3.800], [103.330, 3.800], [103.330, 3.810], [103.320, 3.810], [103.320, 3.800],
]] };

const B = { type: 'Polygon', coordinates: [[
    [103.325, 3.805], [103.335, 3.805], [103.335, 3.815], [103.325, 3.815], [103.325, 3.805],
]] };

test('vertices are counted through every level of nesting', () => {
    assert.equal(countVertices(POINT), 1);
    assert.equal(countVertices(A), 5);
    assert.equal(countVertices({ type: 'MultiPolygon', coordinates: [A.coordinates, B.coordinates] }), 10);
    assert.equal(countVertices({ type: 'Polygon', coordinates: [] }), 0);
});

test('small inputs stay on the client and large ones go to the server', () => {
    assert.equal(route('intersection', [A, B], CAPS), 'client');

    const big = {
        type: 'LineString',
        coordinates: Array.from({ length: 30_000 }, (_, i) => [103 + i * 1e-6, 3.8]),
    };

    assert.equal(route('intersection', [big], CAPS), 'server');
});

test('buffer always goes to the server, however small', () => {
    // Not a size decision. Turf buffers in DEGREE space, so a 250 m buffer at
    // this latitude comes back an ellipse — 250.28 m north-south against
    // 248.62 m east-west — enclosing 0.48% less than a circle of that radius,
    // where GEOS through UTM gives 0.047%. The two disagree by 0.43% and the
    // budget is 0.1%: where the work runs must not be visible in the answer.
    assert.equal(route('buffer', [POINT], CAPS), 'server');
});

test('the elliptical buffer is why, and it is still true', () => {
    // Asserted against Turf directly, so that a future version of Turf making
    // its buffer geodesic shows up here as a failing test rather than as a
    // routing decision nobody revisits.
    const buffered = runLocally('buffer', [POINT], { metres: 250, steps: 32 });
    const radii = buffered.coordinates[0].slice(0, -1).map((p) => distance(POINT.coordinates, p));
    const spread = Math.max(...radii) - Math.min(...radii);

    assert.ok(spread > 1, `Turf's buffer is still elliptical: spread ${spread.toFixed(3)} m`);

    const off = Math.abs(ringArea(buffered.coordinates[0]) / (Math.PI * 250 * 250) - 1) * 100;

    assert.ok(off > 0.1, `and still outside the budget: ${off.toFixed(4)}%`);
});

test('an operation the server alone can do is not offered without a server', () => {
    // A control the user can press that cannot complete is worse than one that
    // is not there.
    assert.equal(available('buffer', { geos: false }), false);
    assert.equal(available('makeValid', { geos: false }), false);
    assert.equal(available('intersection', { geos: false }), true);
    assert.equal(available('buffer', { geos: true }), true);
});

test('with no server, the operations that do not need one still run here', () => {
    assert.equal(route('intersection', [A, B], { geos: false }), 'client');
    assert.equal(route('union', [A, B], { geos: false }), 'client');
});

test('the topological operations produce the areas they should', () => {
    // These agree with the server to seven significant figures, measured —
    // because they are topological rather than metric, and a degree is as good
    // a unit as a metre for deciding which side of an edge a point is on.
    const intersection = ringArea(runLocally('intersection', [A, B]).coordinates[0]);
    const union = ringArea(runLocally('union', [A, B]).coordinates[0]);
    const difference = ringArea(runLocally('difference', [A, B]).coordinates[0]);

    const areaA = ringArea(A.coordinates[0]);
    const areaB = ringArea(B.coordinates[0]);

    assert.ok(Math.abs(union - (areaA + areaB - intersection)) / union < 1e-6, 'union is consistent');
    assert.ok(Math.abs(difference - (areaA - intersection)) / difference < 1e-6, 'difference is consistent');
});

test('an operation with no result answers null rather than throwing', () => {
    // Two shapes that do not overlap have no intersection. That is an answer.
    const far = { type: 'Polygon', coordinates: [[
        [104.0, 4.0], [104.1, 4.0], [104.1, 4.1], [104.0, 4.1], [104.0, 4.0],
    ]] };

    assert.equal(runLocally('intersection', [A, far]), null);
});

test('a hull contains what it was made from', () => {
    const hull = runLocally('convexHull', [A]);

    assert.ok(ringArea(hull.coordinates[0]) >= ringArea(A.coordinates[0]) - 1e-6);
});
