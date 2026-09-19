/**
 * The tree arithmetic: inheritance, tri-state and where a drop lands.
 *
 * These are the parts of the layer tree that are easy to get wrong and
 * invisible when they are — a layer that silently stops drawing, a drop that
 * lands one row from where it was aimed, a group that turns everything back on
 * when it is rechecked.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    childrenOf, descendantsOf, wouldCycle, effectiveVisible, effectiveOpacity,
    groupCheckState, flatten, resolveDrop, dropPosition, classRowsOf, isClassified,
} from '../../resources/js/ui/tree-model.js';

/**
 * A small tree:
 *   Base (group, a0)
 *     Lot        (a0)
 *     Gunatanah  (a1)
 *   Sempadan (group, a1)
 *     Negeri     (a0)
 */
function fixture(overrides = {}) {
    const placement = (id, layerId, parentId, sortKey, extra = {}) => ({
        id, layerId, parentId, sortKey, visible: true, opacity: 1,
        minZoom: null, maxZoom: null, access: 'read', version: 1, ...extra,
    });

    const layer = (id, name, kind = 'vector') => ({ id, name, kind, locked: false, version: 1 });

    const state = {
        layers: {
            10: layer(10, 'Base', 'group'),
            11: layer(11, 'Lot'),
            12: layer(12, 'Gunatanah Semasa'),
            20: layer(20, 'Sempadan', 'group'),
            21: layer(21, 'Sempadan Negeri'),
        },
        placements: {
            1: placement(1, 10, null, 'a0'),
            2: placement(2, 11, 1, 'a0'),
            3: placement(3, 12, 1, 'a1'),
            4: placement(4, 20, null, 'a1'),
            5: placement(5, 21, 4, 'a0'),
        },
        tree: [1, 2, 3, 4, 5],
    };

    for (const [id, patch] of Object.entries(overrides)) {
        Object.assign(state.placements[id], patch);
    }

    return state;
}

test('children come back in sort-key order, not insertion order', () => {
    const state = fixture();

    state.placements[3].sortKey = 'a0V';   // now between Lot and nothing

    assert.deepEqual(childrenOf(state, 1).map((p) => p.id), [2, 3]);

    state.placements[2].sortKey = 'b0';    // Lot moves to the end

    assert.deepEqual(childrenOf(state, 1).map((p) => p.id), [3, 2]);
});

test('a hidden ancestor hides everything beneath it, without touching their flags', () => {
    const state = fixture({ 1: { visible: false } });

    assert.equal(effectiveVisible(state, state.placements[2]), false);

    // The child's own flag is untouched, which is what makes rechecking the
    // group restore the previous state rather than turning everything on.
    assert.equal(state.placements[2].visible, true);

    state.placements[1].visible = true;
    assert.equal(effectiveVisible(state, state.placements[2]), true);
});

test('the zoom band is part of effective visibility, and only when a zoom is given', () => {
    const state = fixture({ 2: { minZoom: 14, maxZoom: 18 } });

    assert.equal(effectiveVisible(state, state.placements[2], 12), false);
    assert.equal(effectiveVisible(state, state.placements[2], 16), true);
    assert.equal(effectiveVisible(state, state.placements[2], 19), false);
    assert.equal(effectiveVisible(state, state.placements[2]), true);
});

test('opacity multiplies down the chain', () => {
    const state = fixture({ 1: { opacity: 0.5 }, 2: { opacity: 0.5 } });

    assert.equal(effectiveOpacity(state, state.placements[2]), 0.25);
    assert.equal(effectiveOpacity(state, state.placements[1]), 0.5);
});

test('a group is indeterminate only when its descendants disagree', () => {
    const state = fixture();

    assert.equal(groupCheckState(state, state.placements[1]), true);

    state.placements[2].visible = false;
    assert.equal(groupCheckState(state, state.placements[1]), null);

    state.placements[3].visible = false;
    assert.equal(groupCheckState(state, state.placements[1]), false);

    // Its own flag wins over its children's: the children are still there.
    state.placements[2].visible = true;
    state.placements[1].visible = false;
    assert.equal(groupCheckState(state, state.placements[1]), false);
});

test('a collapsed group hides its children from the row list', () => {
    const state = fixture();

    assert.deepEqual(flatten(state).map((r) => r.placement.id), [1, 2, 3, 4, 5]);
    assert.deepEqual(flatten(state).map((r) => r.depth), [0, 1, 1, 0, 1]);

    const collapsed = new Set([1]);

    assert.deepEqual(flatten(state, { collapsed }).map((r) => r.placement.id), [1, 4, 5]);
});

test('a filter keeps matches and their ancestors, and reaches into collapsed groups', () => {
    const state = fixture();
    const rows = flatten(state, { collapsed: new Set([1]), filter: 'gunatanah' });

    // The group is kept because its child matched, and expanded despite being
    // collapsed — a hit the user cannot reach is not a hit.
    assert.deepEqual(rows.map((r) => r.placement.id), [1, 3]);
    assert.deepEqual(rows.map((r) => r.matched), [false, true]);
});

test('a group cannot be dropped into itself or into its own descendant', () => {
    const state = fixture();

    assert.equal(wouldCycle(state, 1, 1), true);
    assert.equal(wouldCycle(state, 1, 2), true);
    assert.equal(wouldCycle(state, 1, 4), false);
    assert.equal(wouldCycle(state, 1, null), false);

    assert.equal(resolveDrop(state, 1, 2, 'into'), null);
    assert.equal(resolveDrop(state, 1, 3, 'above'), null);
});

test('dropping above a node lands between it and its previous sibling', () => {
    const state = fixture();
    const drop = resolveDrop(state, 5, 3, 'above');

    assert.equal(drop.parentId, 1);
    assert.ok(drop.sortKey > 'a0' && drop.sortKey < 'a1', `${drop.sortKey} should sit between a0 and a1`);
});

test('dropping below the last node appends rather than bisecting towards z', () => {
    const state = fixture();
    const drop = resolveDrop(state, 5, 3, 'below');

    assert.equal(drop.parentId, 1);
    assert.ok(drop.sortKey > 'a1', `${drop.sortKey} should sort after a1`);

    // Appending must not grow the key: 500 bisections towards an open end
    // produced an 84-character key once already.
    assert.ok(drop.sortKey.length <= 3, `${drop.sortKey} is longer than an append should be`);
});

test('dropping into a group appends inside it', () => {
    const state = fixture();
    const drop = resolveDrop(state, 5, 1, 'into');

    assert.equal(drop.parentId, 1);
    assert.ok(drop.sortKey > 'a1');
});

test('a leaf has no middle, because it cannot contain anything', () => {
    assert.equal(dropPosition(0.1, false), 'above');
    assert.equal(dropPosition(0.6, false), 'below');

    assert.equal(dropPosition(0.1, true), 'above');
    assert.equal(dropPosition(0.5, true), 'into');
    assert.equal(dropPosition(0.9, true), 'below');
});

test('descendants are found at any depth', () => {
    const state = fixture();

    assert.deepEqual(descendantsOf(state, 1).map((p) => p.id), [2, 3]);
    assert.deepEqual(descendantsOf(state, 2).map((p) => p.id), []);
});

// --------------------------------------------------------------- sublayers

/** The land-use classification, as the tree would hold it. */
function classified(state, placementId = 3) {
    state.placements[placementId].classification = {
        field: 'gunatanah_kategori',
        classes: [
            { value: 'Perumahan', label: 'Perumahan', visible: true, opacity: 1, style: {} },
            { value: 'Pertanian', label: '', visible: false, opacity: 0.5, style: {} },
        ],
        other: { label: 'Lain-lain', visible: true, opacity: 1, style: {} },
    };

    return state;
}

test('an unclassified layer has no sublayer rows', () => {
    const state = fixture();

    assert.deepEqual(classRowsOf(state.placements[3]), []);
    assert.equal(isClassified(state.placements[3]), false);
});

test('a classified layer lists its classes and then the other bucket', () => {
    const state = classified(fixture());
    const rows = classRowsOf(state.placements[3]);

    assert.deepEqual(rows.map((r) => r.value), ['Perumahan', 'Pertanian', 'other']);
    assert.equal(rows[2].isOther, true);
});

test('flatten puts sublayer rows directly under their layer, one level deeper', () => {
    const state = classified(fixture());
    const rows = flatten(state);

    const at = rows.findIndex((r) => r.kind === 'node' && r.placement.id === 3);

    assert.equal(rows[at].depth, 1);
    assert.equal(rows[at].hasChildren, true, 'the layer must offer a twisty');

    // Its three sublayers follow it immediately, and the next real node comes
    // after them rather than between.
    assert.deepEqual(rows.slice(at + 1, at + 4).map((r) => [r.kind, r.value, r.depth]), [
        ['class', 'Perumahan', 2],
        ['class', 'Pertanian', 2],
        ['class', 'other', 2],
    ]);

    assert.equal(rows[at + 4].kind, 'node');
    assert.equal(rows[at + 4].placement.id, 4);
});

test('a sublayer row falls back to its value when it has no label', () => {
    const state = classified(fixture());
    const rows = flatten(state).filter((r) => r.kind === 'class');

    assert.equal(rows[0].label, 'Perumahan');
    assert.equal(rows[1].label, 'Pertanian', 'an empty label is not a blank row');
    assert.equal(rows[2].label, 'Lain-lain');
});

test('collapsing a classified layer hides its sublayers', () => {
    const state = classified(fixture());
    const rows = flatten(state, { collapsed: new Set([3]) });

    assert.equal(rows.filter((r) => r.kind === 'class').length, 0);
});

test('sublayer rows never claim to be droppable placements', () => {
    // They carry their layer's placement so the row can command it, which is
    // exactly why a drop target must be chosen by `kind` and not by the
    // presence of `placement`.
    const state = classified(fixture());
    const row = flatten(state).find((r) => r.kind === 'class');

    assert.equal(row.placement.id, 3);
    assert.equal(row.hasChildren, false);
});
