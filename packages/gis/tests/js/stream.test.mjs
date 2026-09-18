/**
 * Unit tests for the streamed feature read: the frame reader and the
 * accumulator that grows one layer's arrays a chunk at a time.
 *
 * Both are pure — bytes in, typed arrays out — so they are tested here rather
 * than through a browser. What they have to get right is arithmetic: index
 * arrays rebased onto what is already held, and frames reassembled out of
 * whatever pieces the network delivered.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { FRAME_FEATURES, FRAME_TRAILER, readFrames } from '../../resources/js/data/stream.js';
import { FeatureAccumulator } from '../../resources/js/data/accumulator.js';
import { buildGeometry, POLYGON } from '../../resources/js/map/geometry.js';

/** One framed payload, as the server writes it. */
function frame(kind, payload) {
    const out = new Uint8Array(5 + payload.length);
    new DataView(out.buffer).setUint8(0, kind);
    new DataView(out.buffer).setUint32(1, payload.length, true);
    out.set(payload, 5);

    return out;
}

/** A body that hands out `size` bytes at a time, to force reassembly. */
function bodyOf(bytes, size) {
    let offset = 0;

    return new ReadableStream({
        pull(controller) {
            if (offset >= bytes.length) {
                controller.close();

                return;
            }

            controller.enqueue(bytes.slice(offset, offset + size));
            offset += size;
        },
    });
}

function concat(parts) {
    const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
    let offset = 0;

    for (const part of parts) {
        out.set(part, offset);
        offset += part.length;
    }

    return out;
}

async function collect(stream) {
    const frames = [];

    for await (const f of readFrames(stream)) {
        frames.push(f);
    }

    return frames;
}

const body = concat([
    frame(FRAME_FEATURES, new Uint8Array([1, 2, 3])),
    frame(FRAME_FEATURES, new Uint8Array([4, 5])),
    frame(FRAME_TRAILER, new TextEncoder().encode('{"returned":5}')),
]);

test('reads frames back whole however the bytes were delivered', async () => {
    // One byte at a time is the pathological case: every frame header is split
    // across four reads. Anything that works there works for real chunks.
    for (const size of [1, 3, 7, 1024]) {
        const frames = await collect(bodyOf(body, size));

        assert.equal(frames.length, 3);
        assert.deepEqual(Array.from(frames[0].payload), [1, 2, 3]);
        assert.deepEqual(Array.from(frames[1].payload), [4, 5]);
        assert.equal(frames[2].kind, FRAME_TRAILER);
        assert.equal(JSON.parse(new TextDecoder().decode(frames[2].payload)).returned, 5);
    }
});

test('gives each payload a buffer of its own, starting at zero', async () => {
    // A GIS1 document is read by pointing a Float64Array at an offset inside
    // its buffer, which is only legal when the buffer starts where the document
    // starts. A view into a shared network chunk would fail on alignment.
    for (const f of await collect(bodyOf(body, 2))) {
        assert.equal(f.payload.byteOffset, 0);
        assert.equal(f.payload.buffer.byteLength, f.payload.byteLength);
    }
});

test('refuses a stream that stops inside a frame', async () => {
    await assert.rejects(
        () => collect(bodyOf(body.slice(0, 7), 4)),
        /Truncated feature stream/,
    );
});

/** A square of `side` degrees, in the shape the GeoJSON encoding sends. */
function square(id, lng, lat, side, area) {
    return {
        id,
        geometry: {
            type: 'Polygon',
            coordinates: [[
                [lng, lat],
                [lng + side, lat],
                [lng + side, lat + side],
                [lng, lat + side],
                [lng, lat],
            ]],
        },
        properties: { _area: area },
    };
}

const features = [
    square(1, 103.3, 3.8, 0.01, 1000),
    square(2, 103.4, 3.8, 0.02, 4000),
    square(3, 103.5, 3.8, 0.03, 9000),
    square(4, 103.6, 3.8, 0.04, 16000),
];

function collectionOf(from, to) {
    return { type: 'FeatureCollection', features: features.slice(from, to) };
}

test('accumulates chunks into exactly what one whole response would have been', () => {
    const whole = buildGeometry(collectionOf(0, 4));

    const accumulator = new FeatureAccumulator();
    accumulator.append(buildGeometry(collectionOf(0, 1)));
    accumulator.append(buildGeometry(collectionOf(1, 3)));
    accumulator.append(buildGeometry(collectionOf(3, 4)));

    const g = accumulator.geometry;

    assert.equal(g.count, whole.count);
    assert.deepEqual(Array.from(g.ids.subarray(0, g.count)), Array.from(whole.ids));
    assert.deepEqual(Array.from(g.area.subarray(0, g.count)), Array.from(whole.area));
    assert.deepEqual(Array.from(g.types.subarray(0, g.count)), Array.from(whole.types));
    assert.deepEqual(Array.from(g.bbox.subarray(0, g.count * 4)), Array.from(whole.bbox));

    // The index arrays are the ones that have to be rebased, and the sentinel
    // each carries has to be rewritten rather than appended.
    assert.deepEqual(
        Array.from(g.featStarts.subarray(0, g.count + 1)),
        Array.from(whole.featStarts),
    );
    assert.deepEqual(
        Array.from(g.ringStarts.subarray(0, g.featStarts[g.count] + 1)),
        Array.from(whole.ringStarts),
    );
    assert.deepEqual(
        Array.from(g.coords.subarray(0, whole.coords.length)),
        Array.from(whole.coords),
    );
    assert.equal(g.types[0], POLYGON);
});

test('keeps the same geometry object as its arrays grow', () => {
    const accumulator = new FeatureAccumulator();
    const held = accumulator.geometry;

    for (let i = 0; i < 4; i++) {
        accumulator.append(buildGeometry(collectionOf(i, i + 1)));
    }

    // The renderer and the index hold this object and re-read its fields, so
    // growth must replace the arrays on it, never hand back a new object.
    assert.equal(accumulator.geometry, held);
    assert.equal(held.count, 4);
});

test('concatenates the attribute tails in feature order', () => {
    const accumulator = new FeatureAccumulator();

    accumulator.append(buildGeometry(collectionOf(0, 1)), [{ lot: 'A' }]);
    accumulator.append(buildGeometry(collectionOf(1, 3)), [{ lot: 'B' }, { lot: 'C' }]);

    assert.deepEqual(accumulator.properties.map((p) => p.lot), ['A', 'B', 'C']);
});
