/**
 * Resolving a classification into paint slots.
 *
 * Nothing here throws when it is wrong — a value mapped to the wrong slot just
 * paints the wrong colour, and a map of fourteen categories is exactly where
 * nobody would notice.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    PALETTE, darken, defaultClassification, otherSlot, slotMap, paintTable,
    isUsable, paintStateCount, classStyle,
} from '../../resources/js/map/style/classify.js';
import { OTHER } from '../../resources/js/data/accumulator.js';
import { movedBefore, successorOf } from '../../resources/js/store/commands/layer.js';

test('a new classification gives every class a colour and a darker outline', () => {
    const c = defaultClassification('gunatanah_kategori', ['Perumahan', 'Pertanian']);

    assert.equal(c.field, 'gunatanah_kategori');
    assert.equal(c.classes[0].style.fill, PALETTE[0]);
    assert.notEqual(c.classes[0].style.stroke, c.classes[0].style.fill);
    assert.ok(c.classes.every((entry) => entry.visible && entry.opacity === 1));
});

test('the palette wraps rather than running out', () => {
    const values = Array.from({ length: PALETTE.length + 2 }, (_, i) => `v${i}`);
    const c = defaultClassification('f', values);

    assert.equal(c.classes[PALETTE.length].style.fill, PALETTE[0]);
});

test('darken keeps the channel order it was given', () => {
    assert.equal(darken('#ffffff', 0.5), '#808080');
    assert.equal(darken('#ff0000', 0.5), '#800000');
    assert.equal(darken('#000000'), '#000000');
});

test('maps each dictionary value to its class, and the rest to other', () => {
    const c = defaultClassification('f', ['Perumahan', 'Pertanian']);

    // A dictionary in a different order from the classification, with a value
    // nobody classified in the middle of it.
    const slots = slotMap(c, ['Pertanian', 'Hutan', 'Perumahan']);

    assert.equal(slots[0], 1);
    assert.equal(slots[1], otherSlot(c), 'an unclassified value is the leftovers, not a class');
    assert.equal(slots[2], 0);
});

test('the other index is itself the other slot, never a dictionary position', () => {
    const c = defaultClassification('f', ['Perumahan']);
    const slots = slotMap(c, ['Perumahan']);

    assert.equal(slots[OTHER], otherSlot(c));
});

test('a class style is merged over the layer style, not substituted for it', () => {
    // A class that sets only a fill must keep the layer's line width, or the
    // outlines change thickness the moment a layer is classified.
    const c = defaultClassification('f', ['Perumahan']);

    c.classes[0].style = { fill: '#ff0000' };

    const table = paintTable(c, { fill: '#00ff00', stroke: '#000000', weight: 3 });

    assert.equal(table[0].style.fill, '#ff0000');
    assert.equal(table[0].style.weight, 3);
    assert.equal(table[0].style.stroke, '#000000');
});

test('the other bucket is the last entry and paints like the layer by default', () => {
    const c = defaultClassification('f', ['Perumahan']);
    const table = paintTable(c, { fill: '#00ff00' });

    assert.equal(table.length, 2);
    assert.equal(table[otherSlot(c)].style.fill, '#00ff00');
    assert.equal(table[otherSlot(c)].visible, true);
});

test('a hidden class is still a slot, so the indices do not shift', () => {
    const c = defaultClassification('f', ['A', 'B']);

    c.classes[0].visible = false;

    const table = paintTable(c);

    assert.equal(table[0].visible, false);
    assert.equal(table[1].visible, true);
    assert.equal(paintStateCount(c), 2, 'two visible: B and other');
});

test('refuses a document that would paint nothing', () => {
    assert.equal(isUsable(null), false);
    assert.equal(isUsable({ field: 'f', classes: [] }), false);
    assert.equal(isUsable({ classes: [{ value: 'a' }] }), false);
    assert.equal(isUsable(defaultClassification('f', ['a'])), true);
});

test('an empty style from PHP is an object, not an array of methods', () => {
    // PHP encodes an empty map as `[]`. `[].fill` is Array.prototype.fill — a
    // function, so `?? fallback` never fires and the swatch paints nothing.
    assert.deepEqual(classStyle({ style: [] }), {});
    assert.deepEqual(classStyle({ style: null }), {});
    assert.deepEqual(classStyle({}), {});
    assert.deepEqual(classStyle({ style: { fill: '#ff0000' } }), { fill: '#ff0000' });

    assert.equal(classStyle({ style: [] }).fill, undefined, 'not Array.prototype.fill');
});

test('a class with an empty style falls back to the layer, not to nothing', () => {
    const c = defaultClassification('f', ['A']);

    c.classes[0].style = [];

    assert.equal(paintTable(c, { fill: '#00ff00' })[0].style.fill, '#00ff00');
});

// ---------------------------------------------------- reordering sublayers

test('moving a class before another lands it exactly there', () => {
    const classes = [{ value: 'A' }, { value: 'B' }, { value: 'C' }, { value: 'D' }];
    const at = (list) => list.map((e) => e.value).join('');

    assert.equal(at(movedBefore(classes, 'D', 'A')), 'DABC', 'to the front');
    assert.equal(at(movedBefore(classes, 'A', 'C')), 'BACD', 'forward past one');
    assert.equal(at(movedBefore(classes, 'C', 'B')), 'ACBD', 'backward past one');
    assert.equal(at(movedBefore(classes, 'A', null)), 'BCDA', 'to the end');
});

test('a move that cannot be placed goes to the end rather than vanishing', () => {
    // The target can be removed by someone else while the drag is in flight.
    const classes = [{ value: 'A' }, { value: 'B' }];

    assert.deepEqual(movedBefore(classes, 'A', 'gone').map((e) => e.value), ['B', 'A']);
});

test('moving a class before itself is a no-op in effect', () => {
    const classes = [{ value: 'A' }, { value: 'B' }, { value: 'C' }];

    assert.deepEqual(movedBefore(classes, 'B', 'B').map((e) => e.value), ['A', 'C', 'B']);
    assert.deepEqual(movedBefore(classes, 'X', 'A').map((e) => e.value), ['A', 'B', 'C'], 'unknown class');
});

test('the successor is what undo restores a class in front of', () => {
    const classes = [{ value: 'A' }, { value: 'B' }, { value: 'C' }];

    assert.equal(successorOf(classes, 'A'), 'B');
    assert.equal(successorOf(classes, 'C'), null, 'last has no successor');
    assert.equal(successorOf(classes, 'X'), null);
    assert.equal(successorOf(undefined, 'A'), null);
});

test('class order is paint order, and slot 0 is the one on top', () => {
    // The slot map is built from list order, so the first class is slot 0 —
    // and the renderer paints descending, which puts slot 0 last and on top.
    const c = defaultClassification('f', ['Top', 'Middle', 'Bottom']);
    const slots = slotMap(c, ['Bottom', 'Top', 'Middle']);

    assert.equal(slots[1], 0, 'Top is slot 0');
    assert.equal(slots[2], 1);
    assert.equal(slots[0], 2);
    assert.equal(otherSlot(c), 3, 'the leftovers hold the highest slot, so they paint underneath');
});
