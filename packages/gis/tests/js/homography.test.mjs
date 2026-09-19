/**
 * The overlay's projective transform.
 *
 * The property that matters is round-tripping: every corner the user dragged
 * must land exactly where they dropped it, because the handles are drawn at
 * those coordinates and any drift shows as the image sliding out from under
 * its own handle.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { homography, toMatrix3d, project, solve } from '../../resources/js/map/overlay/homography.js';

/** The image's own corners, as the overlay always supplies them. */
const UNIT = [[0, 0], [1, 0], [1, 1], [0, 1]];

function assertClose(actual, expected, message, tolerance = 1e-9) {
    assert.ok(
        Math.abs(actual - expected) < tolerance,
        `${message}: ${actual} is not within ${tolerance} of ${expected}`,
    );
}

test('every corner lands exactly where it was dropped', () => {
    const quad = [[10, 10], [90, 30], [80, 90], [20, 70]];
    const h = homography(UNIT, quad);

    UNIT.forEach((source, i) => {
        const [x, y] = project(h, source);

        assertClose(x, quad[i][0], `corner ${i} x`);
        assertClose(y, quad[i][1], `corner ${i} y`);
    });
});

test('an axis-aligned rectangle comes out affine, with no perspective', () => {
    // The initial placement is always a rectangle, so this is the transform an
    // overlay is born with. Its perspective terms must be zero, or the image
    // is subtly keystoned before anyone has touched it.
    const h = homography(UNIT, [[0, 0], [100, 0], [100, 50], [0, 50]]);

    assertClose(h[6], 0, 'perspective x');
    assertClose(h[7], 0, 'perspective y');
    assert.deepEqual(project(h, [0.5, 0.5]).map(Math.round), [50, 25]);
});

test('a genuinely projective quad keeps its perspective terms', () => {
    // A plan photographed at an angle. An affine fit would flatten this to a
    // parallelogram and move a corner the user never touched.
    const h = homography(UNIT, [[0, 0], [100, 20], [90, 80], [10, 60]]);

    assert.ok(Math.abs(h[6]) + Math.abs(h[7]) > 1e-6, 'perspective terms must survive');
});

test('the centre of a keystoned quad is not the average of its corners', () => {
    // The point of a projective transform: it is not linear, so the image's
    // middle does not land at the quad's centroid.
    const quad = [[0, 0], [100, 0], [80, 60], [20, 20]];
    const h = homography(UNIT, quad);
    const [cx] = project(h, [0.5, 0.5]);
    const average = quad.reduce((sum, [x]) => sum + x, 0) / 4;

    assert.notEqual(Math.round(cx), Math.round(average));
});

test('a degenerate quad is refused rather than returning infinities', () => {
    // Collapsing corners onto each other is reachable by dragging, and an
    // unchecked solve returns Infinity — an image that vanishes mid-drag and
    // reappears when the pointer moves on.
    assert.equal(homography(UNIT, [[0, 0], [0, 0], [0, 0], [0, 0]]), null);
    assert.equal(homography(UNIT, [[0, 0], [10, 0], [20, 0], [30, 0]]), null, 'all collinear');
});

test('partial pivoting survives a zero in the first pivot position', () => {
    // Reachable by dragging a corner onto the same row as another.
    const x = solve([[0, 1], [1, 0]], [2, 3]);

    assert.deepEqual(x, [3, 2]);
});

test('matrix3d is column-major with the perspective terms in the fourth row', () => {
    const h = [1, 2, 3, 4, 5, 6, 7, 8, 1];
    const values = toMatrix3d(h).replace('matrix3d(', '').replace(')', '').split(', ').map(Number);

    assert.equal(values.length, 16);
    // First column is the image's x axis: h11, h21, 0, h31.
    assert.deepEqual(values.slice(0, 4), [1, 4, 0, 7]);
    assert.deepEqual(values.slice(4, 8), [2, 5, 0, 8]);
    assert.deepEqual(values.slice(8, 12), [0, 0, 1, 0], 'the plane is untouched in z');
    assert.deepEqual(values.slice(12), [3, 6, 0, 1], 'translation, then the fixed scale');
});
