/**
 * Reading the `GIS1` wire format.
 *
 * Every array here is a **view** onto the response buffer, not a copy. That is
 * the whole point of the format: a 20,000-feature GeoJSON response costs 151 ms
 * to parse into the same arrays, and this costs the time to read a 64-byte
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

export function decodeGis1(buffer) {
    const view = new DataView(buffer);

    if (view.getUint32(0, true) !== MAGIC) {
        throw new Error('Not a GIS1 response');
    }

    const version = view.getUint16(4, true);

    if (version !== 1) {
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
    const propertiesOffset = view.getUint32(52, true);

    const coords = new Float64Array(buffer, coordsOffset, vertexCount * 2);

    // In place: read longitude and latitude, write x and y over them.
    for (let v = 0; v < vertexCount; v++) {
        coords[v * 2] = projectLng(coords[v * 2]);
        coords[v * 2 + 1] = projectLat(coords[v * 2 + 1]);
    }

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

    const layout = {
        count,
        ringCount,
        vertexCount,
        coordsOffset,
        bboxOffset,
        idsOffset,
        areaOffset,
        ringStartsOffset,
        featStartsOffset,
        typesOffset,
    };

    const properties = (flags & 1) === 1 && propertiesLength > 0
        ? JSON.parse(new TextDecoder().decode(new Uint8Array(buffer, propertiesOffset, propertiesLength)))
        : null;

    return { geometry, properties, layout };
}

/**
 * Rebuild the views after the buffer has crossed a worker boundary.
 *
 * The arrays are views onto one buffer, so it is transferred once and re-viewed
 * here rather than sent seven times — which would fail on the second, the
 * buffer having already been detached.
 */
export function viewGis1(buffer, layout) {
    return {
        count: layout.count,
        coords: new Float64Array(buffer, layout.coordsOffset, layout.vertexCount * 2),
        bbox: new Float64Array(buffer, layout.bboxOffset, layout.count * 4),
        ids: new Float64Array(buffer, layout.idsOffset, layout.count),
        area: new Float64Array(buffer, layout.areaOffset, layout.count),
        ringStarts: new Uint32Array(buffer, layout.ringStartsOffset, layout.ringCount + 1),
        featStarts: new Uint32Array(buffer, layout.featStartsOffset, layout.count + 1),
        types: new Uint8Array(buffer, layout.typesOffset, layout.count),
    };
}
