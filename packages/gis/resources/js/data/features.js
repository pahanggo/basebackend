/**
 * Fetching features and turning them into typed arrays.
 *
 * Parsing happens in the worker so a 50 MB response does not stall the frame
 * loop, and the arrays come back as transferable buffers, so the handoff moves
 * memory rather than copying it.
 *
 * The binary response is **a stream of frames**, each a complete `GIS1`
 * document of at most 100 features. Each frame is parsed and handed up as it
 * lands, so the map fills in from the first tenth of a second rather than
 * staying empty until the last feature has been read. Because the server
 * orders by area, the frames arrive most-visible-first, which is what makes
 * the partial picture worth looking at.
 *
 * GeoJSON is still read whole: it is the readable encoding, a single JSON
 * document by definition, and incremental parsing of one would mean shipping a
 * streaming JSON parser to serve the debugging path.
 */

import { fromTransfer } from '../map/geometry.js';
import { viewGis1 } from './gis1.js';
import { FeatureAccumulator } from './accumulator.js';
import { FRAME_FEATURES, FRAME_TRAILER, readFrames } from './stream.js';

export const GEOJSON_TYPE = 'application/geo+json';
export const BINARY_TYPE = 'application/vnd.gis.features+gis1-stream';

let worker = null;
let nextId = 1;
const pending = new Map();

function ensureWorker() {
    if (worker !== null) {
        return worker;
    }

    worker = new Worker(new URL('../map/worker/parse.worker.js', import.meta.url), { type: 'module' });

    worker.onmessage = (event) => {
        const resolver = pending.get(event.data.id);

        if (!resolver) {
            return;
        }

        pending.delete(event.data.id);

        if (event.data.error) {
            resolver.reject(new Error(event.data.error));

            return;
        }

        resolver.resolve({
            geometry: event.data.binary
                ? viewGis1(event.data.buffer, event.data.layout, event.data.coordsBuffer)
                : fromTransfer(event.data),
            properties: event.data.properties || null,
            cull: event.data.cull,
        });
    };

    return worker;
}

/**
 * @param {ArrayBuffer} buffer an encoded GeoJSON FeatureCollection
 */
export function parseInWorker(buffer, { binary = false } = {}) {
    const id = nextId++;

    return new Promise((resolve, reject) => {
        pending.set(id, { resolve, reject });
        ensureWorker().postMessage({ id, buffer, binary }, [buffer]);
    });
}

/**
 * Read one layer's features for a viewport.
 *
 * `minArea` is not sent for an ordinary viewport read — the server derives the
 * threshold from the zoom, and a client that could choose its own could ask for
 * all 164,000 candidates, which is the one request the design exists to
 * prevent. It is passed only by the performance harness, deliberately.
 *
 * @param {Object} options
 * @param {string} options.apiBase
 * @param {number} options.layerId
 * @param {Array<number>} options.bbox minx, miny, maxx, maxy
 * @param {number} options.zoom
 * @param {number|null} options.minArea
 * @param {Function|null} options.onChunk called with each streamed chunk, as it
 *        is appended, so the renderer can paint a partial read
 * @param {Array<number>|null} options.held a box every feature of which the
 *        caller already has, so a pan asks only for what is new
 * @param {FeatureAccumulator|null} options.into append into this rather than
 *        starting a fresh set, which is what makes a pan additive
 */
export async function fetchFeatures({
    apiBase,
    layerId,
    bbox,
    zoom,
    minArea = null,
    binary = true,
    signal = null,
    onChunk = null,
    held = null,
    into = null,
}) {
    const params = new URLSearchParams({
        bbox: bbox.map((n) => n.toFixed(6)).join(','),
        zoom: String(zoom),
    });

    if (minArea !== null) {
        params.set('minArea', String(minArea));
    }

    if (held !== null) {
        params.set('held', held.map((n) => n.toFixed(6)).join(','));
    }

    // Content negotiation, not a `?format=` parameter: the two encodings are
    // the same resource. `GIS1` is asked for whenever the renderer is the
    // consumer, which is always except when a human is reading the response.
    const started = performance.now();
    const response = await fetch(`${apiBase}/layers/${layerId}/features?${params}`, {
        headers: { Accept: binary ? BINARY_TYPE : GEOJSON_TYPE },
        credentials: 'same-origin',
        signal,
    });

    if (!response.ok) {
        throw new Error(`Feature read failed: ${response.status}`);
    }

    return binary
        ? readStream(response, started, onChunk, into)
        : readWhole(response, started);
}

/**
 * The binary encoding: one frame at a time, painted as it arrives.
 *
 * Frames are parsed in order and one at a time. Parsing 100 features costs
 * well under a millisecond, so there is nothing to gain from overlapping it
 * with the read, and doing so would mean appending chunks in whatever order
 * the worker happened to finish them.
 */
async function readStream(response, started, onChunk, into = null) {
    const accumulator = into ?? new FeatureAccumulator();
    let bytes = 0;
    let parseMs = 0;
    let cull = null;
    let chunks = 0;
    let firstChunkMs = null;

    for await (const frame of readFrames(response.body)) {
        bytes += frame.payload.byteLength + 5;

        if (frame.kind === FRAME_TRAILER) {
            cull = JSON.parse(new TextDecoder().decode(frame.payload));
            break;
        }

        if (frame.kind !== FRAME_FEATURES) {
            // Forwards compatible on purpose: the frame header carries a length
            // precisely so an unknown kind can be stepped over.
            continue;
        }

        const parseStarted = performance.now();
        const parsed = await parseInWorker(frame.payload.buffer, { binary: true });

        parseMs += performance.now() - parseStarted;
        chunks++;

        const range = accumulator.append(parsed.geometry, parsed.properties);

        if (firstChunkMs === null) {
            firstChunkMs = performance.now() - started;
        }

        if (onChunk) {
            onChunk({
                geometry: accumulator.geometry,
                properties: accumulator.properties,
                first: chunks === 1,
                ...range,
            });
        }
    }

    return {
        geometry: accumulator.geometry,
        properties: accumulator.properties,
        cull: cull || {},
        timing: {
            transferMs: performance.now() - started,
            parseMs,
            firstChunkMs,
            chunks,
            bytes,
        },
    };
}

/** The readable encoding, which is one document and is read as one. */
async function readWhole(response, started) {
    // An ArrayBuffer, not text. `response.text()` decodes on the main thread
    // and `postMessage` of the resulting string copies it again; a buffer is
    // transferred to the worker, which decodes it there.
    const buffer = await response.arrayBuffer();
    const transferred = performance.now() - started;
    const bytes = buffer.byteLength;

    const parseStarted = performance.now();
    const parsed = await parseInWorker(buffer, { binary: false });

    return {
        ...parsed,
        timing: {
            transferMs: transferred,
            parseMs: performance.now() - parseStarted,
            firstChunkMs: transferred,
            chunks: 1,
            bytes,
        },
        cull: parsed.cull || {},
    };
}
