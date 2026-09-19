/**
 * Turning a gesture, or a set of typed numbers, into coordinates.
 *
 * The property under test throughout is that the numbers come back out: a
 * rectangle asked for as 100 x 50 metres measures 100 x 50 on the ground, not
 * in degrees. This tool is for cadastral and planning work, where that is the
 * entire point (specification section 9).
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    rectangleRing, rectangleFromDrag, circleRing, radiusFromDrag,
    constrainToAngle, simplify, CIRCLE_SEGMENTS,
} from '../../resources/js/map/draw/shapes.js';
import { distance, ringArea, bearing } from '../../resources/js/lib/measure.js';

const KUANTAN = [103.3260, 3.8077];

function within(actual, expected, tolerance, message) {
    assert.ok(
        Math.abs(actual - expected) <= tolerance,
        `${message}: ${actual} is not within ${tolerance} of ${expected}`,
    );
}

test('a rectangle measures on the ground what was typed', () => {
    const ring = rectangleRing(KUANTAN, 100, 50, 90);

    within(distance(ring[0], ring[1]), 100, 0.001, 'width');
    within(distance(ring[1], ring[2]), 50, 0.001, 'height');
    within(distance(ring[2], ring[3]), 100, 0.001, 'opposite width');
    within(distance(ring[3], ring[0]), 50, 0.001, 'opposite height');
});

test('a rectangle is closed and has four corners', () => {
    const ring = rectangleRing(KUANTAN, 100, 50);

    assert.equal(ring.length, 5);
    assert.deepEqual(ring[0], ring[4]);
});

test('a rotated rectangle keeps its dimensions', () => {
    // Rotation turns the shape; it does not stretch it.
    for (const rotation of [0, 37, 90, 180, 315]) {
        const ring = rectangleRing(KUANTAN, 240, 60, rotation);

        within(distance(ring[0], ring[1]), 240, 0.001, `width at ${rotation}`);
        within(distance(ring[1], ring[2]), 60, 0.001, `height at ${rotation}`);
        within(bearing(ring[0], ring[1]), rotation, 0.001, `bearing at ${rotation}`);
    }
});

test('a large rectangle still measures what was typed', () => {
    // The failure a degree-space rectangle has is that neither side measures
    // what was asked for. Here the two sides walked from the origin do, exactly.
    const ring = rectangleRing([103.0, 3.0], 50_000, 50_000, 90);

    within(distance(ring[0], ring[1]), 50_000, 0.001, 'width');
    within(distance(ring[0], ring[3]), 50_000, 0.001, 'height');

    // The far side is NOT the same length, and that is geodesy rather than a
    // bug: a quadrilateral on a curved surface cannot have four right angles
    // and equal opposite sides at once. Over 50 km it comes to 19 m, which is
    // worth knowing about before someone reports it.
    const far = distance(ring[2], ring[3]);

    assert.ok(Math.abs(far - 50_000) < 25, `far side ${far}`);
    assert.ok(Math.abs(far - 50_000) > 1, 'the curvature effect is real, not noise');
});

test('a dragged rectangle and a typed one are the same object', () => {
    // Both paths are first-class, so both end in `rectangleRing`. If they
    // diverged, a shape drawn and a shape typed would differ in the sixth
    // decimal and nobody would notice until an export.
    const dragged = rectangleFromDrag([103.30, 3.80], [103.31, 3.805]);
    const width = distance([103.30, 3.80], [103.31, 3.80]);
    const height = distance([103.30, 3.80], [103.30, 3.805]);
    const typed = rectangleRing([103.30, 3.80], width, height, 90);

    dragged.forEach((point, i) => {
        within(point[0], typed[i][0], 1e-12, `corner ${i} longitude`);
        within(point[1], typed[i][1], 1e-12, `corner ${i} latitude`);
    });
});

test('a dragged rectangle does not care which corner started the drag', () => {
    const a = rectangleFromDrag([103.30, 3.80], [103.31, 3.805]);
    const b = rectangleFromDrag([103.31, 3.805], [103.30, 3.80]);

    assert.deepEqual(a, b);
});

test('every point on a circle is the radius from its centre', () => {
    const ring = circleRing(KUANTAN, 250);

    for (const point of ring) {
        within(distance(KUANTAN, point), 250, 0.001, 'radius');
    }
});

test('a circle is a closed ring of the configured segment count', () => {
    const ring = circleRing(KUANTAN, 100);

    assert.equal(ring.length, CIRCLE_SEGMENTS + 1);
    assert.deepEqual(ring[0], ring[CIRCLE_SEGMENTS]);
});

test('a circle encloses what a circle of that radius should', () => {
    // A 72-gon inscribed in a circle has (n/2) R^2 sin(2pi/n) of its area,
    // which is 99.873%. Matching pi r^2 exactly would mean the ring was
    // circumscribed instead, and every vertex would be off the radius.
    const ring = circleRing(KUANTAN, 250);
    const ideal = Math.PI * 250 * 250;

    within(ringArea(ring) / ideal, 0.99873, 0.0001, 'area against pi r squared');
});

test('the radius of a drag is the distance dragged', () => {
    within(radiusFromDrag(KUANTAN, [103.3300, 3.8077]), distance(KUANTAN, [103.33, 3.8077]), 1e-9, 'radius');
});

test('shift constrains the bearing and keeps the distance', () => {
    // Turned onto the nearest allowed line, not moved along a different one.
    const loose = [103.3300, 3.8100];
    const constrained = constrainToAngle(KUANTAN, loose);

    within(distance(KUANTAN, constrained), distance(KUANTAN, loose), 1e-6, 'distance kept');
    const snapped = bearing(KUANTAN, constrained);

    // Distance to the nearest multiple, not a modulo: 14.9999999 % 15 is
    // 14.9999999, which is as far from zero as a number can be and still be
    // correct.
    within(Math.abs(snapped - Math.round(snapped / 15) * 15), 0, 1e-6, 'bearing snapped');
});

test('a freehand stroke loses its samples and keeps its shape', () => {
    // A pointer emits a sample every few milliseconds; nobody means to place
    // four hundred vertices along one kerb line.
    const stroke = Array.from({ length: 400 }, (_, i) => [
        103.32 + i * 1e-5,
        3.80 + Math.sin(i / 7) * 2e-6,
    ]);

    assert.equal(simplify(stroke, 1e-5).length, 2, 'a near-straight line collapses');

    const corner = [[0, 0], [1, 0], [2, 0], [2, 1], [2, 2]];

    assert.deepEqual(simplify(corner, 0.1), [[0, 0], [2, 0], [2, 2]], 'a corner survives');
});

test('simplification keeps the ends, and does nothing without a tolerance', () => {
    const stroke = [[0, 0], [1, 0.5], [2, 0], [3, 0.5], [4, 0]];

    assert.deepEqual(simplify(stroke, 0), stroke);
    assert.deepEqual(simplify([[0, 0], [1, 1]], 10), [[0, 0], [1, 1]]);

    const reduced = simplify(stroke, 0.1);

    assert.deepEqual(reduced[0], stroke[0]);
    assert.deepEqual(reduced[reduced.length - 1], stroke[stroke.length - 1]);
});
