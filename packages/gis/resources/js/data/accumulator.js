/**
 * One layer's typed arrays, grown a chunk at a time.
 *
 * The streamed read delivers `stream_chunk` features per frame, each decoded
 * into its own small set of arrays. Everything above this — the renderer, the
 * spatial index, the hit test — expects one flat set per layer, indexed by
 * feature number, so the chunks are appended into arrays that grow.
 *
 * **The geometry object's identity never changes.** The renderer and the index
 * both hold a reference to it and re-read its fields on every use, so growing
 * an array means assigning a bigger one onto the same object rather than
 * handing out a new object that half the application is not holding.
 *
 * Arrays are allocated with room to spare and the count, not the length, says
 * how much is real — the alternative is a copy of everything on every frame.
 */

/** @returns {Float64Array|Uint32Array|Uint8Array} the same array, or a bigger one holding its contents */
function grown(array, needed) {
    if (array.length >= needed) {
        return array;
    }

    let capacity = Math.max(array.length * 2, 16);

    while (capacity < needed) {
        capacity *= 2;
    }

    const bigger = new array.constructor(capacity);
    bigger.set(array);

    return bigger;
}

export class FeatureAccumulator {
    constructor() {
        this.geometry = {
            count: 0,
            coords: new Float64Array(0),
            bbox: new Float64Array(0),
            ids: new Float64Array(0),
            area: new Float64Array(0),
            ringStarts: new Uint32Array(1),
            featStarts: new Uint32Array(1),
            types: new Uint8Array(0),
        };

        this.properties = null;
        this.vertices = 0;
        this.rings = 0;
    }

    /**
     * Append one decoded chunk.
     *
     * Both index arrays are rebased: a chunk counts its rings and vertices from
     * zero, and here they continue from what is already held. The trailing
     * sentinel each array carries is rewritten rather than appended, so the
     * arrays stay in the shape the renderer walks — `featStarts[f + 1]` is
     * always readable, for the last feature too.
     *
     * @param {Object} chunk decoded geometry for up to `stream_chunk` features
     * @param {Array<Object>|null} properties the chunk's attribute tail
     * @returns {{from: number, to: number}} the range of feature indices added
     */
    append(chunk, properties = null) {
        const g = this.geometry;
        const from = g.count;
        const to = from + chunk.count;

        g.coords = grown(g.coords, (this.vertices + chunk.coords.length / 2) * 2);
        g.coords.set(chunk.coords, this.vertices * 2);

        g.bbox = grown(g.bbox, to * 4);
        g.bbox.set(chunk.bbox.subarray(0, chunk.count * 4), from * 4);

        g.ids = grown(g.ids, to);
        g.ids.set(chunk.ids.subarray(0, chunk.count), from);

        g.area = grown(g.area, to);
        g.area.set(chunk.area.subarray(0, chunk.count), from);

        g.types = grown(g.types, to);
        g.types.set(chunk.types.subarray(0, chunk.count), from);

        const chunkRings = chunk.featStarts[chunk.count];

        g.ringStarts = grown(g.ringStarts, this.rings + chunkRings + 1);

        for (let r = 0; r <= chunkRings; r++) {
            g.ringStarts[this.rings + r] = this.vertices + chunk.ringStarts[r];
        }

        g.featStarts = grown(g.featStarts, to + 1);

        for (let f = 0; f <= chunk.count; f++) {
            g.featStarts[from + f] = this.rings + chunk.featStarts[f];
        }

        if (properties !== null) {
            this.properties = (this.properties ?? []).concat(properties);
        }

        this.vertices += chunk.ringStarts[chunkRings];
        this.rings += chunkRings;
        g.count = to;

        return { from, to };
    }

    /**
     * Drop the features a predicate rejects, closing the gaps they leave.
     *
     * Panning appends, so without this the held set only ever grows: pan ten
     * screens and the arrays hold ten screens of features, of which one screen
     * is on screen. Everything here is index arithmetic — each survivor's rings
     * and vertices are copied down and its `ringStarts` rebased onto the new
     * position, which is `append` in reverse.
     *
     * This is why eviction is batched rather than done per pan. It is O(n) over
     * everything held, and it forces the renderer to rebuild its paths, since
     * `Path2D` has no way to remove a subpath.
     *
     * @param {(f: number) => boolean} shouldKeep
     * @returns {number} how many features were dropped
     */
    compact(shouldKeep) {
        const g = this.geometry;
        const survivors = [];

        for (let f = 0; f < g.count; f++) {
            if (shouldKeep(f)) {
                survivors.push(f);
            }
        }

        if (survivors.length === g.count) {
            return 0;
        }

        let rings = 0;
        let vertices = 0;

        for (const f of survivors) {
            const firstRing = g.featStarts[f];
            const lastRing = g.featStarts[f + 1];

            rings += lastRing - firstRing;
            vertices += g.ringStarts[lastRing] - g.ringStarts[firstRing];
        }

        const dropped = g.count - survivors.length;
        const next = {
            count: survivors.length,
            coords: new Float64Array(vertices * 2),
            bbox: new Float64Array(survivors.length * 4),
            ids: new Float64Array(survivors.length),
            area: new Float64Array(survivors.length),
            ringStarts: new Uint32Array(rings + 1),
            featStarts: new Uint32Array(survivors.length + 1),
            types: new Uint8Array(survivors.length),
        };

        let ringOut = 0;
        let vertexOut = 0;

        for (let i = 0; i < survivors.length; i++) {
            const f = survivors[i];
            const firstRing = g.featStarts[f];
            const lastRing = g.featStarts[f + 1];
            const firstVertex = g.ringStarts[firstRing];
            const lastVertex = g.ringStarts[lastRing];

            next.featStarts[i] = ringOut;
            next.ids[i] = g.ids[f];
            next.area[i] = g.area[f];
            next.types[i] = g.types[f];
            next.bbox.set(g.bbox.subarray(f * 4, f * 4 + 4), i * 4);

            for (let r = firstRing; r < lastRing; r++) {
                next.ringStarts[ringOut++] = vertexOut + (g.ringStarts[r] - firstVertex);
            }

            next.coords.set(g.coords.subarray(firstVertex * 2, lastVertex * 2), vertexOut * 2);

            vertexOut += lastVertex - firstVertex;
        }

        next.featStarts[survivors.length] = ringOut;
        next.ringStarts[rings] = vertexOut;

        // Assigned onto the held object, never swapped for a new one.
        Object.assign(g, next);

        this.rings = ringOut;
        this.vertices = vertexOut;

        return dropped;
    }
}
