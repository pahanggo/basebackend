/**
 * Fetching features and turning them into typed arrays.
 *
 * Parsing happens in the worker so a 50 MB response does not stall the frame
 * loop, and the arrays come back as transferable buffers, so the handoff moves
 * memory rather than copying it.
 *
 * S3 replaces the JSON body with the `GIS1` binary encoding at this same URL
 * through content negotiation; this module is where that swap happens, and
 * nothing above it should need to know.
 */

import { fromTransfer } from '../map/geometry.js';
import { viewGis1 } from './gis1.js';

export const GEOJSON_TYPE = 'application/geo+json';
export const BINARY_TYPE = 'application/vnd.gis.features+gis1';

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
                ? viewGis1(event.data.buffer, event.data.layout)
                : fromTransfer(event.data),
            properties: event.data.properties || null,
            keep: event.data.keep ? new Uint8Array(event.data.keep) : null,
            cull: event.data.cull,
        });
    };

    return worker;
}

/**
 * @param {ArrayBuffer} buffer an encoded GeoJSON FeatureCollection
 * @param {number} simplifyThreshold vertex count above which to simplify
 */
export function parseInWorker(buffer, { binary = false, simplifyThreshold = 64 } = {}) {
    const id = nextId++;

    return new Promise((resolve, reject) => {
        pending.set(id, { resolve, reject });
        ensureWorker().postMessage({ id, buffer, binary, simplifyThreshold }, [buffer]);
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
 */
export async function fetchFeatures({
    apiBase,
    layerId,
    bbox,
    zoom,
    minArea = null,
    binary = true,
    signal = null,
}) {
    const params = new URLSearchParams({
        bbox: bbox.map((n) => n.toFixed(6)).join(','),
        zoom: String(zoom),
    });

    if (minArea !== null) {
        params.set('minArea', String(minArea));
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

    // An ArrayBuffer, not text. `response.text()` decodes on the main thread
    // and `postMessage` of the resulting string copies it again; a buffer is
    // transferred to the worker, which decodes it there.
    const buffer = await response.arrayBuffer();
    const transferred = performance.now() - started;
    const bytes = buffer.byteLength;

    const parseStarted = performance.now();
    const parsed = await parseInWorker(buffer, { binary });

    return {
        ...parsed,
        timing: {
            transferMs: transferred,
            parseMs: performance.now() - parseStarted,
            bytes,
        },
        cull: parsed.cull || JSON.parse(response.headers.get('X-Gis-Cull') || '{}'),
    };
}
