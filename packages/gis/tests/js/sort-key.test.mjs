/**
 * The client half of fractional indexing.
 *
 * The server has the same algorithm for the cases with no client to ask —
 * copying a map, grouping nodes — so the two are tested against the same
 * properties and `LayerTreeCommandTest` asserts the PHP side of them.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { firstKey, between, afterKey } from '../../resources/js/lib/sort-key.js';

test('there is always room between two keys, however many times you drop between them', () => {
    let left = 'a0';
    let right = 'a1';

    for (let i = 0; i < 200; i += 1) {
        const middle = between(left, right);

        assert.ok(left < middle, `${left} should sort before ${middle}`);
        assert.ok(middle < right, `${middle} should sort before ${right}`);

        // Alternate sides, so the key grows in both directions rather than
        // only one.
        if (i % 2 === 0) {
            right = middle;
        } else {
            left = middle;
        }
    }
});

test('open ends append and prepend', () => {
    assert.equal(firstKey(), 'a0');
    assert.ok('a0' < afterKey('a0'));
    assert.ok(between(null, 'a0') < 'a0');
    assert.ok(between('a0', null) > 'a0');
});

test('a reversed pair appends rather than emitting a key that sorts wrongly', () => {
    // The tree may have moved under the caller. Appending is always valid, if
    // not always where the user aimed.
    const key = between('a5', 'a1');

    assert.ok(key > 'a5');
});

test('keys stay short for ordinary appending', () => {
    let last = firstKey();

    for (let i = 0; i < 500; i += 1) {
        last = afterKey(last);
    }

    // Appending walks the alphabet rather than nesting, so 500 layers in one
    // group do not produce 500-character keys.
    assert.ok(last.length <= 6, `append produced a ${last.length}-character key`);
});
