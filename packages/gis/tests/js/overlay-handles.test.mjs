/**
 * Rotating an image overlay.
 *
 * Rotation happens in screen pixels and not in degrees, because a degree of
 * longitude is shorter than a degree of latitude everywhere except the equator
 * — so turning the stored coordinates directly would squash the image as it
 * turned. These tests use a map stub whose two axes have deliberately DIFFERENT
 * scales, which is exactly the condition that would expose the mistake.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { CornerHandles } from '../../resources/js/map/overlay/corner-handles.js';

/**
 * A map where one degree of longitude is 50 px and one of latitude is 100 px.
 * Latitude increases upward, as it does on a real map, so y is negated.
 */
const map = {
    latLngToContainerPoint: ([lat, lng]) => ({ x: lng * 50, y: -lat * 100 }),
    containerPointToLatLng: ([x, y]) => ({ lng: x / 50, lat: -y / 100 }),
    getContainer: () => ({
        addEventListener() {}, removeEventListener() {},
        getBoundingClientRect: () => ({ left: 0, top: 0 }),
    }),
    dragging: { enable() {}, disable() {} },
};

function handles(corners) {
    const instance = new CornerHandles({
        map,
        renderer: { setEditPainter() {}, schedule() {} },
        onCommit() {}, onPreview() {},
    });

    instance.target = { corners };

    return instance;
}

/** A 2 x 1 degree rectangle: 100 x 100 px on this map, so visibly square. */
const RECT = { nw: [0, 1], ne: [2, 1], se: [2, 0], sw: [0, 0] };

test('a quarter turn maps each corner onto the next one round', () => {
    const h = handles(RECT);
    const turned = h.rotatedCorners(RECT, Math.PI / 2);
    const near = (a, b) => Math.abs(a - b) < 1e-9;

    // The rectangle is square ON SCREEN, so a quarter turn lands each corner
    // exactly where its neighbour was. In degrees it is 2 x 1 and would not.
    //
    // Clockwise, because screen y points down: a positive angle in the
    // container's coordinates turns the way a clock does on the display.
    assert.ok(near(turned.nw[0], RECT.ne[0]) && near(turned.nw[1], RECT.ne[1]), 'nw takes ne');
    assert.ok(near(turned.ne[0], RECT.se[0]) && near(turned.ne[1], RECT.se[1]), 'ne takes se');
    assert.ok(near(turned.se[0], RECT.sw[0]) && near(turned.se[1], RECT.sw[1]), 'se takes sw');
    assert.ok(near(turned.sw[0], RECT.nw[0]) && near(turned.sw[1], RECT.nw[1]), 'sw takes nw');
});

test('rotation keeps the shape it was given', () => {
    // The whole point of the handle: it turns the quad, it does not shear it.
    const h = handles(RECT);
    const before = h.points();
    const turned = h.rotatedCorners(RECT, 0.7);

    h.target = { corners: turned };

    const after = h.points();
    const side = (p, i, j) => Math.hypot(p[i].x - p[j].x, p[i].y - p[j].y);

    for (const [i, j] of [[0, 1], [1, 2], [2, 3], [3, 0], [0, 2]]) {
        assert.ok(
            Math.abs(side(before, i, j) - side(after, i, j)) < 1e-6,
            `edge ${i}-${j} changed length`,
        );
    }
});

test('rotation leaves the centre where it was', () => {
    const h = handles(RECT);
    const before = h.centre();

    h.target = { corners: h.rotatedCorners(RECT, 1.3) };

    const after = h.centre();

    assert.ok(Math.abs(before.x - after.x) < 1e-6, 'centre x');
    assert.ok(Math.abs(before.y - after.y) < 1e-6, 'centre y');
});

test('a full turn comes back to where it started', () => {
    const h = handles(RECT);
    const turned = h.rotatedCorners(RECT, Math.PI * 2);

    for (const name of ['nw', 'ne', 'se', 'sw']) {
        assert.ok(Math.abs(turned[name][0] - RECT[name][0]) < 1e-9, `${name} lng`);
        assert.ok(Math.abs(turned[name][1] - RECT[name][1]) < 1e-9, `${name} lat`);
    }
});

test('the rotate handle floats outside the quad, off the top edge', () => {
    const h = handles(RECT);
    const rotate = h.rotatePoint();
    const centre = h.centre();

    // Anchored to the middle of the nw-ne edge...
    assert.equal(rotate.anchorX, 50);
    assert.equal(rotate.anchorY, -100);
    // ...and pushed further from the centre than that anchor is.
    assert.ok(
        Math.hypot(rotate.x - centre.x, rotate.y - centre.y)
        > Math.hypot(rotate.anchorX - centre.x, rotate.anchorY - centre.y),
    );
});

test('the rotate handle wins a tie with a corner underneath it', () => {
    // A small or heavily keystoned overlay can bring a corner within grabbing
    // distance of the rotate handle. Rotating by accident is the more
    // surprising of the two, so the corner does not get to claim the pointer.
    const h = handles(RECT);
    const rotate = h.rotatePoint();

    assert.equal(h.handleAt(rotate.x, rotate.y), 'rotate');
    assert.equal(h.handleAt(0, -100), 'nw', 'a corner on its own still works');
    assert.equal(h.handleAt(500, 500), null, 'empty space grabs nothing');
});

test('a collapsed quad still offers a reachable rotate handle', () => {
    // Every corner dragged onto the same point: there is no outward direction
    // to compute, and the handle must not land on NaN.
    const h = handles({ nw: [1, 1], ne: [1, 1], se: [1, 1], sw: [1, 1] });
    const rotate = h.rotatePoint();

    assert.ok(Number.isFinite(rotate.x) && Number.isFinite(rotate.y));
});
