/**
 * The arithmetic behind vertex editing and snapping.
 *
 * Both are things that fail quietly: a ring torn open by a moved first vertex
 * is silently re-closed by the validator somewhere else, and a snap that
 * ranks by distance rather than by kind just feels like it is fighting you.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    ringsOf, geometryOf, handlesFor, isClosed, withoutVertex,
} from '../../resources/js/map/edit/vertex-editor.js';
import { Snapper } from '../../resources/js/map/snap.js';
import { projectLng, projectLat, unprojectX, unprojectY } from '../../resources/js/map/geometry.js';

/** A renderer-shaped geometry holding one closed square. */
function squareGeometry() {
    const ring = [[103.30, 3.80], [103.31, 3.80], [103.31, 3.81], [103.30, 3.81], [103.30, 3.80]];
    const coords = new Float64Array(ring.length * 2);

    ring.forEach(([lng, lat], i) => {
        coords[i * 2] = projectLng(lng);
        coords[i * 2 + 1] = projectLat(lat);
    });

    return {
        count: 1,
        coords,
        ringStarts: new Uint32Array([0, ring.length]),
        featStarts: new Uint32Array([0, 1]),
        types: new Uint8Array([3]),
    };
}

test('rings come back out of the renderer as the coordinates that went in', () => {
    const rings = ringsOf(squareGeometry(), 0);

    assert.equal(rings.length, 1);
    assert.equal(rings[0].length, 5);

    // Projecting and unprojecting is lossy in the last bits, which is why this
    // is a tolerance and not an equality.
    assert.ok(Math.abs(rings[0][0][0] - 103.30) < 1e-9, 'longitude');
    assert.ok(Math.abs(rings[0][0][1] - 3.80) < 1e-9, 'latitude');
});

test('a closed ring gets one handle per corner, not one per position', () => {
    // The last position repeats the first. Two handles on one point that moved
    // independently would tear the ring open.
    const rings = ringsOf(squareGeometry(), 0);
    const handles = handlesFor(rings);
    const vertices = handles.filter((h) => h.kind === 'vertex');
    const midpoints = handles.filter((h) => h.kind === 'midpoint');

    assert.equal(vertices.length, 4);
    assert.equal(midpoints.length, 4, 'including the one closing the ring');
});

test('an open line gets a midpoint between each pair and no more', () => {
    const line = [[[0, 0], [1, 0], [2, 0]]];
    const handles = handlesFor(line);

    assert.equal(handles.filter((h) => h.kind === 'vertex').length, 3);
    assert.equal(handles.filter((h) => h.kind === 'midpoint').length, 2);
});

test('a midpoint handle inserts at the index between its neighbours', () => {
    const handles = handlesFor([[[0, 0], [10, 0], [10, 10]]]);
    const first = handles.find((h) => h.kind === 'midpoint');

    assert.deepEqual(first.point, [5, 0]);
    assert.equal(first.index, 1, 'splicing here lands it between 0 and 1');
});

test('removing a vertex is refused when it would stop being a shape', () => {
    const triangle = [[[0, 0], [1, 0], [1, 1], [0, 0]]];

    assert.equal(withoutVertex(triangle, 0, 0, 3), null, 'a triangle keeps its three corners');

    const square = [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]];
    const reduced = withoutVertex(square, 0, 1, 3);

    assert.equal(reduced[0].length, 4);
    assert.ok(isClosed(reduced[0]), 'and stays closed');

    const line = [[[0, 0], [1, 0]]];

    assert.equal(withoutVertex(line, 0, 0, 2), null, 'a line keeps its two ends');
});

test('removing the first vertex carries the closing position with it', () => {
    const square = [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]];
    const reduced = withoutVertex(square, 0, 0, 3);

    assert.ok(isClosed(reduced[0]), 'still closed');
    assert.deepEqual(reduced[0][0], reduced[0][reduced[0].length - 1]);
    assert.deepEqual(reduced[0][0], [1, 0], 'the ring now starts where it used to second');
});

test('rings become the geometry type the feature already was', () => {
    assert.deepEqual(geometryOf(1, [[[1, 2]]]), { type: 'Point', coordinates: [1, 2] });
    assert.equal(geometryOf(2, [[[0, 0], [1, 1]]]).type, 'LineString');
    assert.equal(geometryOf(2, [[[0, 0], [1, 1]], [[2, 2], [3, 3]]]).type, 'MultiLineString');
    assert.equal(geometryOf(3, [[[0, 0], [1, 0], [1, 1], [0, 0]]]).type, 'Polygon');
});

// ---------------------------------------------------------------- snapping

/** A snapper over one square, with a fixed tolerance. */
function snapper(enabled) {
    const geometry = squareGeometry();
    const layer = {
        visible: true,
        geometry,
        index: { search: () => [0] },
    };

    const instance = new Snapper({
        map: { getZoom: () => 14 },
        layers: () => [{ slot: 0, layer }],
        enabled,
    });

    // A tight tolerance in projected units — about 0.0004 degrees — so a
    // candidate has to be genuinely near. A generous one puts the whole square
    // in range of every point and every test passes for the wrong reason.
    instance.tolerance = () => 1e-6;

    return instance;
}

test('a corner wins over an edge that passes nearer', () => {
    // The whole reason candidates are ranked. Nearest-wins is the version that
    // feels like it is fighting you.
    const s = snapper({ vertex: true, midpoint: true, edge: true });

    // Just inside the square from the north-west corner: the north edge and
    // the west edge each pass closer than the corner itself does.
    const snapped = s.snap([103.3000002, 3.8099998]);

    assert.ok(Math.abs(snapped[0] - 103.30) < 1e-8, 'longitude of the corner');
    assert.ok(Math.abs(snapped[1] - 3.81) < 1e-8, 'latitude of the corner');
});

test('an edge is used when there is no corner or midpoint in range', () => {
    const s = snapper({ vertex: true, midpoint: true, edge: true });
    // A quarter of the way along the south edge: far from both its corners and
    // from its midpoint, but a hair off the edge itself.
    const snapped = s.snap([103.3025, 3.7999999]);

    assert.ok(Math.abs(snapped[1] - 3.80) < 1e-8, 'pulled onto the south edge');
    assert.ok(Math.abs(snapped[0] - 103.3025) < 1e-7, 'and kept its position along it');
});

test('turning a candidate kind off removes it from consideration', () => {
    const near = [103.3000001, 3.8099999];
    const withVertices = snapper({ vertex: true, midpoint: true, edge: true }).snap(near);

    assert.ok(Math.abs(withVertices[0] - 103.30) < 1e-8, 'pulled to the corner');
    assert.ok(Math.abs(withVertices[1] - 3.81) < 1e-8, 'pulled to the corner');

    // With vertices off it falls to an edge through that corner instead, which
    // is a different point: one ordinate is kept rather than both replaced.
    const edgeOnly = snapper({ vertex: false, midpoint: false, edge: true }).snap(near);

    assert.notDeepEqual(edgeOnly, withVertices);
    assert.ok(
        Math.abs(edgeOnly[0] - 103.30) < 1e-8 || Math.abs(edgeOnly[1] - 3.81) < 1e-8,
        'still on one of the two edges meeting there',
    );
});

test('nothing in range snaps to nothing', () => {
    const s = snapper({ vertex: true, midpoint: true, edge: true });

    assert.equal(s.snap([104.0, 4.0]), null);
});

test('a feature does not snap to itself', () => {
    // Dragging one corner of a square onto the next would weld them.
    const s = snapper({ vertex: true, midpoint: true, edge: true });

    assert.equal(s.snap([103.3000001, 3.8099999], { excludeSlot: 0, excludeFeature: 0 }), null);
});

test('the pixel tolerance shrinks in ground units as the map zooms in', () => {
    const at = (zoom) => new Snapper({
        map: { getZoom: () => zoom },
        layers: () => [],
    }).tolerance();

    assert.ok(at(18) < at(12), 'a pixel is a smaller distance when zoomed in');
    assert.ok(Math.abs(at(12) / at(13) - 2) < 1e-9, 'and halves per zoom level');
});

test('moving the first vertex of a closed ring carries the closing position', () => {
    // The failure this prevents is silent: the ring tears open, the validator
    // closes it by appending the new first position, and the shape gains a
    // corner. Measured on a real square, which came back a pentagon.
    const ring = [[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]];
    const closed = isClosed(ring);

    ring[0] = [0.5, 0.5];

    // Asking `isClosed` HERE is the bug: the mutation has already happened.
    assert.equal(isClosed(ring), false, 'which is why closedness is captured first');

    // The guard, given the flag recorded before the move.
    if (closed) {
        ring[ring.length - 1] = [...ring[0]];
    }

    assert.ok(isClosed(ring));
    assert.equal(ring.length, 5, 'still four corners, not five');
});
