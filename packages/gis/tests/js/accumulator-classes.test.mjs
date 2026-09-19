/**
 * The per-feature class index, across appends and across an eviction.
 *
 * Both halves are the same hazard in two places. A streamed frame numbers its
 * own dictionary from zero, so two frames disagree about what `0` means until
 * the accumulator renumbers them; and `compact` rebuilds every typed array, so
 * an array it forgets keeps its old contents at their old positions and starts
 * meaning something else. Neither failure throws — the map simply paints the
 * wrong colours, which is why these are tested rather than eyeballed.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { FeatureAccumulator, OTHER } from '../../resources/js/data/accumulator.js';

/**
 * A chunk of `count` one-vertex features, each carrying the class index given.
 */
function chunk(classes) {
    const count = classes.length;

    return {
        count,
        coords: new Float64Array(count * 2),
        bbox: new Float64Array(count * 4),
        ids: new Float64Array(count).map((_, i) => i),
        area: new Float64Array(count).fill(1),
        ringStarts: new Uint32Array(count + 1).map((_, i) => i),
        featStarts: new Uint32Array(count + 1).map((_, i) => i),
        types: new Uint8Array(count).fill(1),
        classes: new Uint8Array(classes),
    };
}

/** The class value held for each feature, resolved through the dictionary. */
function values(accumulator) {
    const held = [];

    for (let f = 0; f < accumulator.geometry.count; f++) {
        const at = accumulator.geometry.classes[f];

        held.push(at === OTHER ? null : accumulator.dictionary[at]);
    }

    return held;
}

test('renumbers each frame into one dictionary', () => {
    const accumulator = new FeatureAccumulator();

    // Two frames that both use index 0 and 1, for different values. Appending
    // them without renumbering would make half the features the wrong class.
    accumulator.append(chunk([0, 1, 0]), null, ['Perumahan', 'Pertanian']);
    accumulator.append(chunk([0, 1]), null, ['Hutan', 'Perumahan']);

    assert.deepEqual(values(accumulator), [
        'Perumahan', 'Pertanian', 'Perumahan', 'Hutan', 'Perumahan',
    ]);

    // Three distinct values across five features, each stored once.
    assert.deepEqual(accumulator.dictionary, ['Perumahan', 'Pertanian', 'Hutan']);
});

test('passes the other bucket through rather than treating it as a position', () => {
    const accumulator = new FeatureAccumulator();

    accumulator.append(chunk([0, OTHER, 1]), null, ['Perumahan', 'Hutan']);

    assert.deepEqual(values(accumulator), ['Perumahan', null, 'Hutan']);
    assert.equal(accumulator.dictionary.length, 2);
});

test('keeps every feature with its own class through an eviction', () => {
    const accumulator = new FeatureAccumulator();

    accumulator.append(chunk([0, 1, 0, 1, 0]), null, ['Perumahan', 'Pertanian']);

    // Drop the even-numbered features, as a pan out of the held box would.
    const dropped = accumulator.compact((f) => f % 2 === 1);

    assert.equal(dropped, 3);
    assert.equal(accumulator.geometry.count, 2);
    assert.deepEqual(values(accumulator), ['Pertanian', 'Pertanian']);
});

test('leaves the class array absent when the read did not classify', () => {
    const accumulator = new FeatureAccumulator();
    const plain = chunk([0, 0]);

    delete plain.classes;

    accumulator.append(plain, null, null);

    assert.equal(accumulator.geometry.classes, undefined);

    // And compaction must not invent one.
    accumulator.compact((f) => f === 0);

    assert.equal(accumulator.geometry.classes, undefined);
});

test('the attribute tail is compacted with everything else', () => {
    // It is a plain array beside the typed ones, so it has to be rebuilt
    // explicitly. Forgetting it does not shrink it: it keeps its old contents
    // at their old positions, and every row in the attribute table then
    // belongs to a different feature than the one it is shown against.
    const accumulator = new FeatureAccumulator();

    accumulator.append(chunk([0, 0, 0, 0, 0]), [
        { lot: 'A' }, { lot: 'B' }, { lot: 'C' }, { lot: 'D' }, { lot: 'E' },
    ], ['x']);

    accumulator.compact((f) => f % 2 === 1);

    assert.equal(accumulator.geometry.count, 2);
    assert.deepEqual(accumulator.properties, [{ lot: 'B' }, { lot: 'D' }]);
});

test('an accumulator with no attributes stays without them', () => {
    const accumulator = new FeatureAccumulator();

    accumulator.append(chunk([0, 0]), null, ['x']);
    accumulator.compact((f) => f === 0);

    assert.equal(accumulator.properties, null);
});
