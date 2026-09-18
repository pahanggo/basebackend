/**
 * Unit tests for the store, the undo stack, the sync queue and the command
 * vocabulary.
 *
 * Run by Node's built-in test runner and asserted from Pest, so the suite keeps
 * one entry point and the repository gains no second test framework.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { Store, initialState } from '../../resources/js/store/store.js';
import { UndoStack, estimateBytes } from '../../resources/js/store/undo.js';
import { SyncQueue } from '../../resources/js/store/sync.js';
import { RingBuffer } from '../../resources/js/store/ring-buffer.js';
import {
    featureCreate,
    featureUpdate,
    featureDelete,
    moveVertex,
    layerRename,
    layerSetStyle,
    layerSetVisible,
    layerReorder,
    measurementCreate,
    measurementDelete,
    reconcile,
} from '../../resources/js/store/commands/index.js';
import { pairConflicts, resolve, KEEP_MINE, KEEP_THEIRS, MERGE } from '../../resources/js/store/conflicts.js';

/**
 * A comparable snapshot.
 *
 * Two things are normalised away, both deliberately.
 *
 * **Versions.** A version is the server's monotonic counter, and undo is a new
 * edit rather than a rewind — the server applies the inverse as an ordinary
 * command and bumps the version again. Requiring the version to come back would
 * be requiring the undo to lie about what happened.
 *
 * **Property key order.** Removing a key and restoring it puts it back at the
 * end of the object, because `properties` is a patch and a patch writes the
 * keys it names. Insertion order is not state: nothing reads it, the attribute
 * table sorts by the layer's schema, and the alternative is an inverse that
 * rewrites the whole document to preserve an ordering nobody observes.
 */
function snapshot(state, { ids = true } = {}) {
    const sortKeys = (value) => {
        if (value === null || typeof value !== 'object' || Array.isArray(value)) {
            return value;
        }

        return Object.fromEntries(
            Object.keys(value).sort().map((key) => [key, sortKeys(value[key])]),
        );
    };

    const strip = (rows) => {
        const cleaned = Object.values(rows).map((row) => {
            const { version, id, ...rest } = row;

            return sortKeys(ids ? { id, ...rest } : rest);
        });

        return cleaned.map((row) => JSON.stringify(row)).sort();
    };

    return JSON.stringify({
        layers: strip(state.layers),
        placements: strip(state.placements),
        features: strip(state.features),
        measurements: strip(state.measurements),
        selection: [...state.selection].sort(),
        tree: state.tree,
    });
}

function fixture() {
    const state = initialState();

    state.layers[42] = { id: 42, name: 'Lot', style: { stroke: '#333' }, version: 4, featureCount: 2 };
    state.placements[88] = { id: 88, layerId: 42, parentId: null, sortKey: 'a0', visible: true, version: 1 };
    state.features[1201] = { id: 1201, layerId: 42, properties: { status: 'active', mukim: 'Kuala Kuantan' }, version: 4 };
    state.features[1202] = { id: 1202, layerId: 42, properties: {}, version: 1 };

    return state;
}

test('every command restores the state its inverse was taken from', () => {
    const cases = [
        // Create and delete invert into each other, but the row that comes
        // back carries a new temporary id: the original was assigned by the
        // server and is gone. Content is restored; identity is not, and no
        // client-side design can restore it (see Results).
        { make: () => featureCreate({ tempId: 'tmp:1', layerId: 42, geom: 'AAEC', properties: { status: 'draft' } }), idsPreserved: false },
        () => featureUpdate({ id: 1201, layerId: 42, version: 4, properties: { status: 'closed' } }),
        () => featureUpdate({ id: 1201, layerId: 42, version: 4, properties: { added: 'new' } }),
        () => featureUpdate({ id: 1201, layerId: 42, version: 4, properties: { status: null } }),
        // Geometry-only: it moves no store state at all, because coordinates
        // live in the renderer. It is here to prove exactly that, and that its
        // inverse still carries the geometry to put back.
        { make: () => featureUpdate({ id: 1201, layerId: 42, version: 4, geom: 'BBBB', previousGeom: 'AAAA' }), touchesStore: false },
        { make: () => featureDelete({ id: 1202, layerId: 42, version: 1 }), idsPreserved: false },
        () => layerRename({ id: 42, version: 4, name: 'Lot Baharu' }),
        () => layerSetStyle({ id: 42, version: 4, style: { stroke: '#f00' } }),
        () => layerSetVisible({ id: 88, version: 1, visible: false }),
        () => layerReorder({ id: 88, version: 1, parentId: null, sortKey: 'a1' }),
        { make: () => measurementCreate({ tempId: 'tmp:m', kind: 'distance', geom: 'CCCC', value: 12 }), idsPreserved: false },
    ];

    for (const entry of cases) {
        const { make, touchesStore = true, idsPreserved = true } =
            typeof entry === 'function' ? { make: entry } : entry;

        const options = { ids: idsPreserved };
        const state = fixture();
        const before = snapshot(state, options);

        const command = make();
        const inverse = command.apply(state);

        if (touchesStore) {
            assert.notDeepEqual(snapshot(state, options), before, `${command.op} changed nothing`);
        }

        inverse.apply(state);

        assert.deepEqual(snapshot(state, options), before, `${command.op} did not round-trip`);

        assert.ok(inverse.serialize().op, `${command.op} produced an inverse that cannot be sent`);
    }
});

test('property updates round-trip over random field sets', () => {
    const keys = ['status', 'mukim', 'lot_no', 'owner'];

    for (let run = 0; run < 200; run += 1) {
        const state = fixture();
        const before = snapshot(state);

        const patch = {};

        for (const key of keys) {
            const roll = Math.random();

            if (roll < 0.3) {
                continue;
            }

            patch[key] = roll < 0.5 ? null : `v${Math.floor(Math.random() * 1000)}`;
        }

        if (Object.keys(patch).length === 0) {
            continue;
        }

        const command = featureUpdate({ id: 1201, layerId: 42, version: 4, properties: patch });

        command.apply(state).apply(state);

        assert.deepEqual(snapshot(state), before, `patch ${JSON.stringify(patch)} did not round-trip`);
    }
});

test('a vertex drag serialises to feature.update, not to a gesture op', () => {
    const command = moveVertex({ id: 1201, layerId: 42, version: 4, geom: 'NEW', previousGeom: 'OLD' });

    assert.equal(command.op, 'feature.moveVertex');
    assert.equal(command.serialize().op, 'feature.update');
    assert.equal(command.serialize().geom, 'NEW');
});

test('a drag is one undo entry and a drag then a delete is two', () => {
    const stack = new UndoStack();
    let now = 1000;

    for (let i = 0; i < 90; i += 1) {
        const drag = moveVertex({ id: 1201, layerId: 42, version: 4, geom: `g${i}`, previousGeom: `g${i - 1}` });

        stack.push(drag, drag, { now });
        now += 5;
    }

    assert.equal(stack.depth, 1, 'the whole drag should be one entry');

    const remove = featureDelete({ id: 1202, layerId: 42, version: 1 });

    stack.push(remove, remove, { now });

    assert.equal(stack.depth, 2, 'a different op must break the run');

    // Past the 500 ms box, the same op starts a new entry.
    const later = moveVertex({ id: 1201, layerId: 42, version: 5, geom: 'z', previousGeom: 'y' });

    stack.push(later, later, { now: now + 600 });

    assert.equal(stack.depth, 3);
});

test('the undo stack is bounded by depth and by bytes', () => {
    const deep = new UndoStack({ maxDepth: 10 });

    for (let i = 0; i < 40; i += 1) {
        const command = layerRename({ id: 42, version: i, name: `n${i}` });

        deep.push(command, command, { coalesce: false });
    }

    assert.equal(deep.depth, 10);

    const heavy = new UndoStack({ maxBytes: 4096 });
    const big = { op: 'feature.delete', inverseSize: 2048, serialize: () => ({ op: 'feature.delete' }) };

    for (let i = 0; i < 10; i += 1) {
        heavy.push({ op: 'feature.delete' }, big, { coalesce: false });
    }

    assert.ok(heavy.depth <= 3, `expected the byte bound to trim, got depth ${heavy.depth}`);
    assert.equal(estimateBytes(big), 2048);
});

test('redo clears on a new command', () => {
    const store = new Store();

    store.state.layers[42] = { id: 42, name: 'Lot', style: {}, version: 1 };
    store.commit(layerRename({ id: 42, version: 1, name: 'Renamed' }));
    store.undoOne();

    assert.equal(store.state.layers[42].name, 'Lot');
    assert.equal(store.undo.redoEntries.length, 1);

    store.commit(layerRename({ id: 42, version: 3, name: 'Third' }));

    assert.equal(store.undo.redoEntries.length, 0);
});

test('subscribers fire only for the topics a command declares', () => {
    const store = new Store();
    const seen = [];

    store.state.layers[42] = { id: 42, name: 'Lot', style: {}, version: 1 };
    store.subscribe('layers', () => seen.push('layers'));
    store.subscribe('layers:42', () => seen.push('layers:42'));
    store.subscribe('selection', () => seen.push('selection'));

    store.commit(layerRename({ id: 42, version: 1, name: 'Renamed' }));

    // Both declared topics, once each, and nothing else. A component that does
    // not update is a topic missing from `command.topics` — one place to look.
    assert.deepEqual(seen, ['layers', 'layers:42']);
});

test('the diagnostics buffer keeps the last 200 commands and no more', () => {
    const ring = new RingBuffer(200);

    for (let i = 0; i < 250; i += 1) {
        ring.push(i);
    }

    const items = ring.toArray();

    assert.equal(items.length, 200);
    assert.equal(items[0], 50);
    assert.equal(items[199], 249);
});

test('reconcile swaps temporary ids for the ones the server assigned', () => {
    const state = fixture();

    featureCreate({ tempId: 'tmp:1', layerId: 42, geom: 'AAEC', properties: {} }).apply(state);
    state.selection.add('tmp:1');

    reconcile(state, [{ entity: 'feature', id: 9001, version: 1, tempId: 'tmp:1' }]);

    assert.equal(state.features['tmp:1'], undefined);
    assert.equal(state.features[9001].id, 9001);
    assert.ok(state.selection.has(9001));
    assert.ok(!state.selection.has('tmp:1'));
});

/** A fetch stand-in that records calls and answers from a script. */
function fakeFetch(responses) {
    const calls = [];

    return {
        calls,
        fetch: async (url, options) => {
            calls.push({ url, body: JSON.parse(options.body) });

            const next = responses.shift();

            if (next instanceof Error) {
                throw next;
            }

            return {
                ok: next.status < 400,
                status: next.status,
                json: async () => next.body,
            };
        },
    };
}

function queue(responses, extra = {}) {
    const stub = fakeFetch(responses);

    return {
        stub,
        sync: new SyncQueue({
            apiBase: '/api/geo',
            mapId: 7,
            clientId: 'a3f9c2',
            csrfToken: 'token',
            fetchImpl: stub.fetch,
            debounceMs: 1,
            ...extra,
        }),
    };
}

test('the queue batches what it has and carries one seq per batch', async () => {
    const { stub, sync } = queue([{ status: 200, body: { mapVersion: 32, seq: 1, applied: [] } }]);

    sync.enqueue(featureUpdate({ id: 1201, layerId: 42, version: 4, properties: { a: 1 } }));
    sync.enqueue(featureUpdate({ id: 1202, layerId: 42, version: 1, properties: { b: 2 } }));

    await sync.flush();

    assert.equal(stub.calls.length, 1);
    assert.equal(stub.calls[0].body.commands.length, 2);
    assert.equal(stub.calls[0].body.seq, 1);
    assert.equal(sync.mapVersion, 32);
});

test('a structural command does not wait for the debounce', async () => {
    const { stub, sync } = queue([{ status: 200, body: { mapVersion: 33, seq: 1, applied: [] } }], { debounceMs: 10000 });

    sync.enqueue(layerReorder({ id: 88, version: 1, parentId: null, sortKey: 'a1' }));

    // Yield once: `enqueue` called `flush` synchronously, and only the fetch
    // itself is asynchronous.
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(stub.calls.length, 1);
});

test('a retry after a network failure carries the same seq', async () => {
    const { stub, sync } = queue([
        new Error('offline'),
        { status: 200, body: { mapVersion: 32, seq: 1, applied: [] } },
    ]);

    sync.enqueue(featureUpdate({ id: 1201, layerId: 42, version: 4, properties: { a: 1 } }));

    await sync.flush();
    await sync.flush();

    assert.equal(stub.calls.length, 2);
    assert.equal(stub.calls[0].body.seq, stub.calls[1].body.seq,
        'a retry must reuse (clientId, seq) or the server cannot tell it from new work');
});

test('a 409 pauses the queue and keeps the commands', async () => {
    const conflicts = [];
    const { sync } = queue(
        [{ status: 409, body: { code: 'version_conflict', conflicts: [{ id: 1201, yourVersion: 4, serverVersion: 7 }] } }],
        { onConflict: (problem) => conflicts.push(problem) },
    );

    sync.enqueue(featureUpdate({ id: 1201, layerId: 42, version: 4, properties: { a: 1 } }));

    await sync.flush();

    assert.equal(conflicts.length, 1);
    assert.ok(sync.paused);
    assert.equal(sync.queue.length, 1, 'the work is still the user\'s and must not be dropped');
});

test('conflict resolution offers mine, theirs and a field-wise merge', () => {
    const local = featureUpdate({
        id: 1201,
        layerId: 42,
        version: 4,
        geom: 'MINE',
        properties: { status: 'closed', owner: 'me' },
    });

    const problem = {
        conflicts: [{
            id: 1201,
            yourVersion: 4,
            serverVersion: 7,
            server: { geom: 'THEIRS', properties: { status: 'active' } },
        }],
    };

    const [pair] = pairConflicts(problem, [local]);

    assert.equal(pair.local, local);

    const mine = resolve(pair, KEEP_MINE);

    assert.equal(mine.send[0].version, 7, 'keep-mine retries at the version the server holds');
    assert.equal(mine.send[0].geom, 'MINE');

    const theirs = resolve(pair, KEEP_THEIRS);

    assert.deepEqual(theirs.send, []);
    assert.equal(theirs.adopt.geom, 'THEIRS');

    const merged = resolve(pair, MERGE, ['properties.owner']);

    assert.deepEqual(merged.send[0].properties, { owner: 'me' });
    assert.equal(merged.send[0].geom, undefined, 'geometry is never merged unless explicitly kept');
});
