/**
 * What the legend decides to show.
 *
 * Generated from the resolved style rather than maintained beside it, which is
 * the only way it cannot drift — a legend a user edits eventually disagrees
 * with the map, and the disagreement is invisible until someone acts on it.
 *
 * The DOM half needs a browser; what is tested here is the selection, which is
 * where the judgement is.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { legendEntries } from '../../resources/js/ui/legend.js';

/**
 * A tree of:
 *   Base (group)
 *     Gunatanah  (vector, classified)
 *     Lot        (vector, plain)
 *   Photo        (image)
 */
function fixture(overrides = {}) {
    const placement = (id, layerId, parentId, sortKey, extra = {}) => ({
        id, layerId, parentId, sortKey, visible: true, opacity: 1,
        minZoom: null, maxZoom: null, access: 'owner', version: 1,
        classification: null, ...extra,
    });

    const state = {
        layers: {
            1: { id: 1, name: 'Base', kind: 'group', style: {} },
            2: { id: 2, name: 'Gunatanah', kind: 'vector', style: { fill: '#7bc47f', stroke: '#2f6f3e' } },
            3: { id: 3, name: 'Lot', kind: 'vector', style: { fill: '#e91e63', stroke: '#880e4f' } },
            4: { id: 4, name: 'Photo', kind: 'image', style: {} },
        },
        placements: {
            10: placement(10, 1, null, 'a0'),
            11: placement(11, 2, 10, 'a0'),
            12: placement(12, 3, 10, 'a1'),
            13: placement(13, 4, null, 'a1'),
        },
        tree: [10, 11, 12, 13],
    };

    for (const [id, patch] of Object.entries(overrides)) {
        Object.assign(state.placements[id], patch);
    }

    return state;
}

/** The selection, without the panel that renders it. */
function legendFor(state, zoom = 14) {
    return { entries: () => legendEntries(state, zoom) };
}

function classify(state, placementId, classes) {
    state.placements[placementId].classification = {
        field: 'gunatanah_kategori',
        classes,
        other: { label: 'Everything else', visible: true, opacity: 1, style: {} },
    };

    return state;
}

test('an unclassified layer gets one entry with its own colours', () => {
    const entries = legendFor(fixture()).entries();
    const lot = entries.find((e) => e.name === 'Lot');

    assert.equal(lot.kind, 'layer');
    assert.equal(lot.swatch.fill, '#e91e63');
    assert.equal(lot.swatch.stroke, '#880e4f');
});

test('a classified layer becomes a heading with its classes under it', () => {
    const state = classify(fixture(), 11, [
        { value: 'Perumahan', label: 'Perumahan', visible: true, style: { fill: '#ffff99' } },
        { value: 'Hutan', label: 'Hutan', visible: true, style: { fill: '#1f78b4' } },
    ]);

    const entries = legendFor(state).entries();
    const at = entries.findIndex((e) => e.name === 'Gunatanah');

    assert.equal(entries[at].kind, 'layer');
    assert.equal(entries[at].swatch, null, 'a heading has no swatch of its own');
    assert.deepEqual(entries.slice(at + 1, at + 4).map((e) => [e.kind, e.name]), [
        ['class', 'Perumahan'],
        ['class', 'Hutan'],
        ['class', 'Everything else'],
    ]);
});

test('a class with no colour of its own falls back to the layer', () => {
    const state = classify(fixture(), 11, [
        { value: 'Perumahan', label: 'Perumahan', visible: true, style: [] },
    ]);

    const entry = legendFor(state).entries().find((e) => e.name === 'Perumahan');

    // `[]` rather than `{}` because that is what PHP sends for an empty map.
    assert.equal(entry.swatch.fill, '#7bc47f');
});

test('hidden things are omitted, not greyed', () => {
    // A legend is a key to what is ON the map. An entry for something absent
    // is an entry the reader has to work out is irrelevant.
    const hidden = legendFor(fixture({ 12: { visible: false } })).entries();

    assert.equal(hidden.some((e) => e.name === 'Lot'), false);

    const state = classify(fixture(), 11, [
        { value: 'Perumahan', label: 'Perumahan', visible: false, style: {} },
        { value: 'Hutan', label: 'Hutan', visible: true, style: {} },
    ]);

    const names = legendFor(state).entries().map((e) => e.name);

    assert.equal(names.includes('Perumahan'), false);
    assert.equal(names.includes('Hutan'), true);
});

test('a layer outside its zoom band is omitted too', () => {
    const away = legendFor(fixture({ 12: { minZoom: 18 } }), 12).entries();

    assert.equal(away.some((e) => e.name === 'Lot'), false);
    assert.equal(legendFor(fixture({ 12: { minZoom: 18 } }), 19).entries().some((e) => e.name === 'Lot'), true);
});

test('a group heading appears only when something under it survives', () => {
    // Otherwise the legend grows headings for nothing.
    const full = legendFor(fixture()).entries();

    assert.equal(full[0].kind, 'group');
    assert.equal(full[0].name, 'Base');

    const empty = legendFor(fixture({ 11: { visible: false }, 12: { visible: false } })).entries();

    assert.equal(empty.some((e) => e.kind === 'group'), false);
});

test('tiles and images have no symbology to explain', () => {
    // Naming one with a blank swatch would say nothing.
    assert.equal(legendFor(fixture()).entries().some((e) => e.name === 'Photo'), false);
});

test('entries follow tree order and nesting depth', () => {
    const entries = legendFor(fixture()).entries();

    assert.deepEqual(entries.map((e) => [e.name, e.depth]), [
        ['Base', 0],
        ['Gunatanah', 1],
        ['Lot', 1],
    ]);
});
