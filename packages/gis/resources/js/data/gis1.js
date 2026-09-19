/**
 * Reading the `GIS1` wire format.
 *
 * Every array here is a **view** onto the response buffer, not a copy. That is
 * the whole point of the format: a 20,000-feature GeoJSON response costs 151 ms
 * to parse into the same arrays, and this costs the time to read an 80-byte
 * header.
 *
 * The one pass over the data is the projection: coordinates arrive as longitude
 * and latitude, because that is what the database stores and what every other
 * consumer expects, and the renderer wants normalised Web Mercator. That runs
 * **in place**, writing back into the same buffer, so it allocates nothing and
 * re-packs nothing.
 */

import { projectLng, projectLat } from '../map/geometry.js';

const MAGIC = 0x31534947;          // 'GIS1' read as a little-endian uint32
const VERSION = 3;
const FLAG_PROPERTIES = 1;
const FLAG_QUANTISED = 2;
const FLAG_CLASSES = 4;
const LNG_BIAS = 180;
const LAT_BIAS = 90;

/**
 * Full-precision coordinates: projected in place, as they always were.
 *
 * The array is a view onto the response buffer, so this rewrites the response
 * itself. That is the point — nothing is allocated and nothing is re-packed.
 */
function projectInPlace(coords, vertexCount) {
    for (let v = 0; v < vertexCount; v++) {
        coords[v * 2] = projectLng(coords[v * 2]);
        coords[v * 2 + 1] = projectLat(coords[v * 2 + 1]);
    }

    return coords;
}

/**
 * Quantised coordinates: unbiased, scaled and projected in one pass.
 *
 * This is the one place the format allocates. A `uint32` section cannot be
 * widened in place, so the projected coordinates need somewhere to go — but the
 * pass itself is the pass that was already happening, and it was never free: it
 * calls a `log` and a `tan` per vertex. Undoing the bias adds a multiply and a
 * subtract to that, which is not measurable beside them.
 */
function expandCoords(buffer, coordsOffset, vertexCount, exponent) {
    const packed = new Uint32Array(buffer, coordsOffset, vertexCount * 2);
    const coords = new Float64Array(vertexCount * 2);
    const scale = 10 ** exponent;

    for (let v = 0; v < vertexCount; v++) {
        coords[v * 2] = projectLng(packed[v * 2] / scale - LNG_BIAS);
        coords[v * 2 + 1] = projectLat(packed[v * 2 + 1] / scale - LAT_BIAS);
    }

    return coords;
}

export function decodeGis1(buffer) {
    const view = new DataView(buffer);

    if (view.getUint32(0, true) !== MAGIC) {
        throw new Error('Not a GIS1 response');
    }

    const version = view.getUint16(4, true);

    if (version !== VERSION) {
        throw new Error(`Unsupported GIS1 version ${version}`);
    }

    const flags = view.getUint16(6, true);
    const count = view.getUint32(8, true);
    const ringCount = view.getUint32(12, true);
    const vertexCount = view.getUint32(16, true);
    const propertiesLength = view.getUint32(20, true);

    const coordsOffset = view.getUint32(24, true);
    const bboxOffset = view.getUint32(28, true);
    const idsOffset = view.getUint32(32, true);
    const areaOffset = view.getUint32(36, true);
    const ringStartsOffset = view.getUint32(40, true);
    const featStartsOffset = view.getUint32(44, true);
    const typesOffset = view.getUint32(48, true);
    const classesOffset = view.getUint32(52, true);
    const classDictOffset = view.getUint32(56, true);
    const propertiesOffset = view.getUint32(60, true);
    const coordExponent = view.getUint32(68, true);
    const classDictLength = view.getUint32(72, true);

    const quantised = (flags & FLAG_QUANTISED) === FLAG_QUANTISED;
    const coords = quantised
        ? expandCoords(buffer, coordsOffset, vertexCount, coordExponent)
        : projectInPlace(new Float64Array(buffer, coordsOffset, vertexCount * 2), vertexCount);

    const bbox = new Float64Array(buffer, bboxOffset, count * 4);

    // The stored bounding boxes are in longitude and latitude too, and the
    // index queries them in projected space. Note that projecting latitude
    // inverts it, so the vertical pair swaps.
    for (let f = 0; f < count; f++) {
        const north = projectLat(bbox[f * 4 + 3]);
        const south = projectLat(bbox[f * 4 + 1]);

        bbox[f * 4] = projectLng(bbox[f * 4]);
        bbox[f * 4 + 2] = projectLng(bbox[f * 4 + 2]);
        bbox[f * 4 + 1] = north;
        bbox[f * 4 + 3] = south;
    }

    const geometry = {
        count,
        coords,
        bbox,
        ids: new Float64Array(buffer, idsOffset, count),
        area: new Float64Array(buffer, areaOffset, count),
        ringStarts: new Uint32Array(buffer, ringStartsOffset, ringCount + 1),
        featStarts: new Uint32Array(buffer, featStartsOffset, count + 1),
        types: new Uint8Array(buffer, typesOffset, count),
    };

    // The class index is local to THIS document, because a streamed response is
    // a sequence of documents and no one of them can see the others. The
    // dictionary that goes with it is what lets the accumulator renumber each
    // frame into the one dictionary it keeps for the layer.
    const classified = (flags & FLAG_CLASSES) === FLAG_CLASSES;

    if (classified) {
        geometry.classes = new Uint8Array(buffer, classesOffset, count);
    }

    const classDict = classified && classDictLength > 0
        ? JSON.parse(new TextDecoder().decode(new Uint8Array(buffer, classDictOffset, classDictLength)))
        : (classified ? [] : null);

    const layout = {
        count,
        ringCount,
        vertexCount,
        quantised,
        coordsOffset,
        bboxOffset,
        idsOffset,
        areaOffset,
        ringStartsOffset,
        featStartsOffset,
        typesOffset,
        classesOffset,
        classified,
    };

    const properties = (flags & FLAG_PROPERTIES) === FLAG_PROPERTIES && propertiesLength > 0
        ? JSON.parse(new TextDecoder().decode(new Uint8Array(buffer, propertiesOffset, propertiesLength)))
        : null;

    return { geometry, properties, classDict, layout };
}

/**
 * Rebuild the views after the buffer has crossed a worker boundary.
 *
 * The arrays are views onto one buffer, so it is transferred once and re-viewed
 * here rather than sent seven times — which would fail on the second, the
 * buffer having already been detached. Quantised coordinates are the exception:
 * they were expanded out of the response into their own buffer, so there are
 * two to transfer and two to re-view.
 */
export function viewGis1(buffer, layout, coordsBuffer = null) {
    return {
        count: layout.count,
        // Quantised coordinates were expanded into a buffer of their own, so
        // they are transferred separately and viewed whole; full-precision ones
        // still live inside the response buffer.
        coords: layout.quantised
            ? new Float64Array(coordsBuffer)
            : new Float64Array(buffer, layout.coordsOffset, layout.vertexCount * 2),
        bbox: new Float64Array(buffer, layout.bboxOffset, layout.count * 4),
        ids: new Float64Array(buffer, layout.idsOffset, layout.count),
        area: new Float64Array(buffer, layout.areaOffset, layout.count),
        ringStarts: new Uint32Array(buffer, layout.ringStartsOffset, layout.ringCount + 1),
        featStarts: new Uint32Array(buffer, layout.featStartsOffset, layout.count + 1),
        types: new Uint8Array(buffer, layout.typesOffset, layout.count),
        ...(layout.classified
            ? { classes: new Uint8Array(buffer, layout.classesOffset, layout.count) }
            : {}),
    };
}
