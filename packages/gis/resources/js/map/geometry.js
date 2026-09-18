/**
 * Flat typed-array geometry storage.
 *
 * Parsed GeoJSON is discarded once it has been through here. The 672,112
 * imported lots average 6.5 vertices each; as GeoJSON objects that is 60-100x
 * the memory of the coordinates themselves, and the allocation churn alone
 * causes visible GC pauses. Everything downstream — the spatial index, the
 * cull, the paint — reads these arrays and never an object per feature.
 *
 * **Coordinates are stored projected, not as longitude and latitude.** Web
 * Mercator normalised to [0, 1] scales linearly with zoom, so a draw becomes
 * one multiply and one subtract per vertex. Projecting at draw time instead
 * costs a `sin` and a `log` per vertex, which measured at 21 ms of a 34 ms
 * repaint — most of the frame, spent recomputing a value that never changes.
 * Longitude and latitude are recovered with `unproject` where they are actually
 * needed, which is editing and export, a few vertices at a time.
 *
 * Layout, with sentinels so ring `r` spans `[ringStarts[r], ringStarts[r + 1])`
 * in vertex indices and feature `f` spans `[featStarts[f], featStarts[f + 1])`
 * in ring indices:
 *
 *   coords      Float64Array   interleaved x, y in normalised Web Mercator
 *   ringStarts  Uint32Array    vertex index per ring, length rings + 1
 *   featStarts  Uint32Array    ring index per feature, length count + 1
 *   types       Uint8Array     POINT | LINE | POLYGON
 *   bbox        Float64Array   4 per feature, projected: minx, miny, maxx, maxy
 *   ids         Float64Array   feature id (exceeds Uint32 range in time)
 *   area        Float64Array   m2 per feature, 0 for points and lines
 */

/**
 * Longitude, latitude to normalised Web Mercator.
 *
 * Matches Leaflet's EPSG3857 exactly: multiply by `256 * 2 ** zoom` to get the
 * pixel coordinates Leaflet works in.
 */
export function projectLng(lng) {
    return (lng + 180) / 360;
}

export function projectLat(lat) {
    const sin = Math.sin(lat * Math.PI / 180);

    return 0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI);
}

export function unprojectX(x) {
    return x * 360 - 180;
}

export function unprojectY(y) {
    return (2 * Math.atan(Math.exp((0.5 - y) * 2 * Math.PI)) - Math.PI / 2) * 180 / Math.PI;
}

export const POINT = 1;
export const LINE = 2;
export const POLYGON = 3;

const TYPES = {
    Point: POINT,
    MultiPoint: POINT,
    LineString: LINE,
    MultiLineString: LINE,
    Polygon: POLYGON,
    MultiPolygon: POLYGON,
};

/**
 * Every ring of a geometry, in draw order, whatever its type.
 *
 * A multi-part geometry is stored as one feature with several rings, because
 * that is how it paints: one `Path2D`, several subpaths.
 *
 * @param {Object} geometry a GeoJSON geometry
 * @returns {Array<Array<Array<number>>>} rings of [lng, lat] pairs
 */
function ringsOf(geometry) {
    switch (geometry.type) {
        case 'Point':
            return [[geometry.coordinates]];
        case 'MultiPoint':
        case 'LineString':
            return [geometry.coordinates];
        case 'MultiLineString':
        case 'Polygon':
            return geometry.coordinates;
        case 'MultiPolygon':
            return geometry.coordinates.flat();
        default:
            return [];
    }
}

/**
 * Build the arrays from a GeoJSON FeatureCollection.
 *
 * Two passes: the first counts so every array is allocated once at its final
 * size, the second fills. Growing arrays as we go would mean repeated copies
 * of what is, at zoom 12, about 17 MB of coordinates.
 *
 * @param {{features: Array<Object>}} collection
 * @param {string} areaProperty property holding pre-computed area in m2
 */
export function buildGeometry(collection, areaProperty = '_area') {
    const features = collection.features || [];
    const count = features.length;

    let ringCount = 0;
    let vertexCount = 0;

    const ringsPerFeature = new Array(count);

    for (let f = 0; f < count; f++) {
        const rings = ringsOf(features[f].geometry || {});
        ringsPerFeature[f] = rings;
        ringCount += rings.length;

        for (let r = 0; r < rings.length; r++) {
            vertexCount += rings[r].length;
        }
    }

    const geometry = {
        count,
        coords: new Float64Array(vertexCount * 2),
        ringStarts: new Uint32Array(ringCount + 1),
        featStarts: new Uint32Array(count + 1),
        types: new Uint8Array(count),
        bbox: new Float64Array(count * 4),
        ids: new Float64Array(count),
        area: new Float64Array(count),
    };

    let ring = 0;
    let vertex = 0;

    for (let f = 0; f < count; f++) {
        const feature = features[f];
        const rings = ringsPerFeature[f];

        geometry.featStarts[f] = ring;
        geometry.types[f] = TYPES[(feature.geometry || {}).type] || POLYGON;
        geometry.ids[f] = Number(feature.id ?? f);

        const properties = feature.properties || {};
        geometry.area[f] = Number(properties[areaProperty] ?? 0);

        let minx = Infinity;
        let miny = Infinity;
        let maxx = -Infinity;
        let maxy = -Infinity;

        for (let r = 0; r < rings.length; r++) {
            geometry.ringStarts[ring++] = vertex;

            const points = rings[r];

            for (let p = 0; p < points.length; p++) {
                const x = projectLng(points[p][0]);
                const y = projectLat(points[p][1]);

                geometry.coords[vertex * 2] = x;
                geometry.coords[vertex * 2 + 1] = y;
                vertex++;

                if (x < minx) { minx = x; }
                if (y < miny) { miny = y; }
                if (x > maxx) { maxx = x; }
                if (y > maxy) { maxy = y; }
            }
        }

        geometry.bbox[f * 4] = minx;
        geometry.bbox[f * 4 + 1] = miny;
        geometry.bbox[f * 4 + 2] = maxx;
        geometry.bbox[f * 4 + 3] = maxy;
    }

    geometry.ringStarts[ringCount] = vertex;
    geometry.featStarts[count] = ring;

    return geometry;
}

/** The buffers to hand across a worker boundary without copying. */
export function transferables(geometry) {
    return [
        geometry.coords.buffer,
        geometry.ringStarts.buffer,
        geometry.featStarts.buffer,
        geometry.types.buffer,
        geometry.bbox.buffer,
        geometry.ids.buffer,
        geometry.area.buffer,
    ];
}

/** Rebuild the views after a structured-clone handoff. */
export function fromTransfer(message) {
    return {
        count: message.count,
        coords: new Float64Array(message.coords),
        ringStarts: new Uint32Array(message.ringStarts),
        featStarts: new Uint32Array(message.featStarts),
        types: new Uint8Array(message.types),
        bbox: new Float64Array(message.bbox),
        ids: new Float64Array(message.ids),
        area: new Float64Array(message.area),
    };
}
