/**
 * The performance overlay's arithmetic.
 *
 * Tested apart from the overlay because it is the part that can be wrong
 * without looking wrong: a frame rate computed over the wrong span reads as a
 * perfectly plausible number, and the whole point of the panel is that the
 * numbers on it can be trusted.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { frameRate, worstGap, BUDGETS } from '../../resources/js/ui/performance-overlay.js';

/** Timestamps for frames at a steady interval. */
function steady(count, everyMs) {
    return Array.from({ length: count }, (_, i) => i * everyMs);
}

test('a steady sixty frames a second reads as sixty', () => {
    const rate = frameRate(steady(31, 1000 / 60));

    assert.ok(Math.abs(rate - 60) < 0.001, `got ${rate}`);
});

test('n timestamps bound n-1 intervals', () => {
    // Counting them as n overstates the rate by a thirtieth at the default
    // window: small, and wrong.
    assert.equal(frameRate([0, 1000]), 1);
    assert.equal(frameRate([0, 500, 1000]), 2);
});

test('fewer than two frames is no answer rather than a wrong one', () => {
    assert.equal(frameRate([]), null);
    assert.equal(frameRate([0]), null);
    assert.equal(frameRate([5, 5]), null, 'a zero span cannot give a rate');
});

test('the worst gap is the stutter, not the average', () => {
    // A single 200 ms frame among sixty good ones is what the user felt, and
    // an average over the window would bury it.
    const frames = [...steady(30, 16), 480 + 200];

    assert.ok(worstGap(frames) > 150, `got ${worstGap(frames)}`);
    assert.ok(frameRate(frames) > 30, 'while the rate still looks acceptable');
});

test('an empty window has no gap', () => {
    assert.equal(worstGap([]), 0);
    assert.equal(worstGap([10]), 0);
});

test('the budgets are the ones section 19 sets', () => {
    // Written once, so the overlay's highlighting and any later gate cannot
    // disagree about what "over" means.
    assert.equal(BUDGETS.fps(54), true);
    assert.equal(BUDGETS.fps(55), false);
    assert.equal(BUDGETS.fps(null), false, 'no reading is not a breach');

    assert.equal(BUDGETS.worstFrame(34), true);
    assert.equal(BUDGETS.worstFrame(33), false);

    assert.equal(BUDGETS.drawn(20_001), true);
    assert.equal(BUDGETS.candidates(250_001), true);
    assert.equal(BUDGETS.buildMs(16.1), true);
    assert.equal(BUDGETS.paintMs(16.1), true);
    assert.equal(BUDGETS.heapBytes(251 * 1048576), true);
    assert.equal(BUDGETS.heapBytes(200 * 1048576), false);
});
