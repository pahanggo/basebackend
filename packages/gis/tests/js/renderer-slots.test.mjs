/**
 * What a renderer slot keeps when its working set is swapped.
 *
 * `replaceGeometry` is called after EVERY read that evicted anything — which
 * is after every pan far enough to fetch. It rebuilds the slot's entry, so any
 * field it forgets to carry over is silently reset on the next pan. That has
 * now happened twice: `order` and `opacity` were the first pair, the
 * classification the second, and the symptom both times was a layer that looked
 * right until the map moved.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

// The renderer is a Leaflet layer, and Leaflet is a global UMD build that is
// not present in Node. `extend` only has to hand the prototype back.
globalThis.L = { Layer: { extend: (proto) => proto } };
globalThis.window = globalThis.window ?? { devicePixelRatio: 1 };

const { GisRenderer } = await import('../../resources/js/map/renderer.js');

/** A renderer stub holding slots, with redraws stubbed out. */
function renderer() {
    return Object.assign(Object.create(GisRenderer), {
        _layers: [],
        schedule() {},
    });
}

const geometry = { count: 0, classes: new Uint8Array(0) };

test('a swapped working set keeps how the layer is painted', () => {
    const r = renderer();
    const slot = r.addGeometry({ geometry, index: null, order: 3, opacity: 0.4 });

    const slotOf = new Uint8Array(256);
    const paints = [{ style: { fill: '#ff0000' }, opacity: 1, visible: true }];

    r.reclassifyGeometry(slot, slotOf);
    r.repaintClasses(slot, paints);

    // What an eviction does: same slot, new geometry.
    r.replaceGeometry(slot, { geometry: { count: 1 }, index: null });

    const layer = r._layers[slot];

    assert.equal(layer.slotOf, slotOf, 'the class-to-slot map must survive a pan');
    assert.equal(layer.paints, paints, 'the colours must survive a pan');
    assert.equal(layer.order, 3);
    assert.equal(layer.opacity, 0.4);
});

test('an unclassified layer stays unclassified across a swap', () => {
    const r = renderer();
    const slot = r.addGeometry({ geometry, index: null });

    r.replaceGeometry(slot, { geometry: { count: 1 }, index: null });

    assert.equal(r._layers[slot].slotOf, null);
    assert.equal(r._layers[slot].paints, null);
});

test('a slot is stable and removal leaves a hole the next layer fills', () => {
    const r = renderer();
    const first = r.addGeometry({ geometry, index: null });
    const second = r.addGeometry({ geometry, index: null });

    r.removeGeometry(first);

    assert.equal(r._layers[first], null);
    assert.equal(r.addGeometry({ geometry, index: null }), first, 'the hole is reused');
    assert.notEqual(second, first);
});

test('the slot map can be replaced mid-stream without invalidating the paths', () => {
    // The dictionary grows as frames arrive, so the map is replaced per chunk.
    // Bumping the generation there would rebuild the whole path every frame.
    const r = renderer();
    const slot = r.addGeometry({ geometry, index: null });
    const before = r._layers[slot].generation;

    r.setSlotMap(slot, new Uint8Array(256));

    assert.equal(r._layers[slot].generation, before, 'streaming must not invalidate');

    r.reclassifyGeometry(slot, new Uint8Array(256));

    assert.equal(r._layers[slot].generation, before + 1, 'a real reclassify must');
});

// ------------------------------------------------------------- paint order

/** The sequence `_paintPaths` would fill and stroke in. */
function paintedOrder(r, layer) {
    const seen = [];
    const ctx = {
        set fillStyle(v) { seen.push(v); },
        set strokeStyle(v) {}, set lineWidth(v) {}, set globalAlpha(v) {},
        fill() {}, stroke() {},
    };

    r._paintPaths(ctx, layer.paths, layer.style ?? null, 1, layer.paints ?? null);

    return seen;
}

test('the first row in the tree paints on top', () => {
    // `order` is tree position, 0 being the first row, and the first row must
    // end up above the others. This sorted the other way until S5d, which put
    // the second layer in the tree over the first.
    const r = renderer();
    const first = r.addGeometry({ geometry, index: null, order: 0 });
    const second = r.addGeometry({ geometry, index: null, order: 1 });

    const sequence = r._ordered().map((l) => l.order);

    assert.deepEqual(sequence, [1, 0], 'deepest row painted first, first row last');
    assert.notEqual(first, second);
});

test('a class paints above the classes below it in the list', () => {
    const r = renderer();
    // Three classes of polygons: slots 0, 1, 2 and the leftovers at 3.
    const paths = new Map([
        [(3 << 9) | 1, 'middle'],
        [(3 << 9) | 3, 'leftovers'],
        [(3 << 9) | 0, 'top'],
        [(3 << 9) | 2, 'bottom'],
    ]);
    const paints = ['top', 'middle', 'bottom', 'leftovers'].map((fill) => ({
        style: { fill }, opacity: 1, visible: true,
    }));

    // Painted first is underneath, so the list reads back-to-front.
    assert.deepEqual(paintedOrder(r, { paths, paints }),
        ['leftovers', 'bottom', 'middle', 'top']);
});

test('order does not depend on the order features happened to arrive', () => {
    // A `Map` iterates by insertion, which is read order — so without an
    // explicit sort, which sublayer covered which changed between reads.
    const r = renderer();
    const keys = [(3 << 9) | 0, (3 << 9) | 1, (3 << 9) | 2];
    const paints = ['a', 'b', 'c'].map((fill) => ({ style: { fill }, opacity: 1, visible: true }));

    const forwards = new Map(keys.map((k, i) => [k, i]));
    const backwards = new Map([...keys].reverse().map((k, i) => [k, i]));

    assert.deepEqual(paintedOrder(r, { paths: forwards, paints }),
        paintedOrder(r, { paths: backwards, paints }));
});

test('within one class, polygons paint under lines under points', () => {
    const r = renderer();
    const paths = new Map([[(1 << 9) | 0, 'point'], [(3 << 9) | 0, 'polygon'], [(2 << 9) | 0, 'line']]);

    r.options = { styles: { 1: { fill: 'point' }, 2: { fill: 'line' }, 3: { fill: 'polygon' } } };

    assert.deepEqual(paintedOrder(r, { paths }), ['polygon', 'line', 'point']);
});

test('a hidden class is skipped rather than painted transparent', () => {
    const r = renderer();
    const paths = new Map([[(3 << 9) | 0, 'a'], [(3 << 9) | 1, 'b']]);
    const paints = [
        { style: { fill: 'a' }, opacity: 1, visible: false },
        { style: { fill: 'b' }, opacity: 1, visible: true },
    ];

    assert.deepEqual(paintedOrder(r, { paths, paints }), ['b']);
});
