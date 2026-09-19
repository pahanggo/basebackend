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
