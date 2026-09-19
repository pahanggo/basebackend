/**
 * One R-tree per layer, over the feature bounding boxes.
 *
 * It answers the only two spatial questions the renderer asks: which features
 * might be in the viewport, and which might be under the cursor. Both are
 * `search`; the difference is the size of the box.
 *
 * Items carry the feature's index into the geometry arrays, never the feature
 * itself, so the index adds one small object per feature and nothing more.
 */

import RBush from 'rbush';

/** Box overlap, written out rather than called through rbush's comparator. */
function overlaps(node, minX, minY, maxX, maxY) {
    return node.minX <= maxX && node.minY <= maxY && node.maxX >= minX && node.maxY >= minY;
}

export class SpatialIndex {
    constructor(geometry) {
        this.geometry = geometry;
        this.tree = new RBush();
        this.rebuild();
    }

    /**
     * Bulk-load from the bbox array.
     *
     * `load` on an empty tree builds by packing, which is far faster than
     * inserting one at a time and produces a better-balanced tree.
     */
    rebuild() {
        const { bbox, count } = this.geometry;
        const items = new Array(count);

        for (let f = 0; f < count; f++) {
            items[f] = {
                minX: bbox[f * 4],
                minY: bbox[f * 4 + 1],
                maxX: bbox[f * 4 + 2],
                maxY: bbox[f * 4 + 3],
                i: f,
            };
        }

        this.tree.clear();
        this.tree.load(items);
        this.items = items;

        // Reused across frames: a viewport query that matched 36,000 features
        // should not allocate 36,000 anything.
        this._scratch = new Uint32Array(Math.max(1024, count));
        this._stack = new Array(64);
    }

    /**
     * Feature indices whose bounding box meets the given one.
     *
     * This walks rbush's tree rather than calling `search`, for one reason:
     * `search` builds an array of the matching item objects, and a zoom-12
     * query matches 36,000 of them. Collecting indices straight into a reused
     * typed array instead measured 17 ms down to 3 ms, and allocates nothing
     * per frame.
     *
     * @returns {Uint32Array} a view onto the scratch buffer, valid until the
     *          next call
     */
    search(minX, minY, maxX, maxY) {
        let node = this.tree.data;
        let n = 0;

        if (!overlaps(node, minX, minY, maxX, maxY)) {
            return this._scratch.subarray(0, 0);
        }

        const stack = this._stack;
        let depth = 0;

        while (node) {
            const children = node.children;

            for (let c = 0; c < children.length; c++) {
                const child = children[c];

                if (!overlaps(child, minX, minY, maxX, maxY)) {
                    continue;
                }

                if (node.leaf) {
                    if (n === this._scratch.length) {
                        this._grow();
                    }

                    this._scratch[n++] = child.i;
                } else {
                    stack[depth++] = child;
                }
            }

            node = depth > 0 ? stack[--depth] : null;
        }

        return this._scratch.subarray(0, n);
    }

    _grow() {
        const bigger = new Uint32Array(this._scratch.length * 2);
        bigger.set(this._scratch);
        this._scratch = bigger;
    }

    /**
     * Add the features in `[from, to)`, which the streamed read just appended.
     *
     * A bulk `load` into an existing tree inserts one item at a time, so this
     * is more expensive per feature than the packing `rebuild` does — but it is
     * paid once per 100-feature chunk against a tree that is still small, and
     * the alternative is rebuilding the whole index 130 times over one read.
     * The hit test stays correct throughout, which is the point: a map that is
     * painting is a map the user is already pointing at.
     */
    append(from, to) {
        const { bbox } = this.geometry;
        const items = new Array(to - from);

        for (let f = from; f < to; f++) {
            items[f - from] = {
                minX: bbox[f * 4],
                minY: bbox[f * 4 + 1],
                maxX: bbox[f * 4 + 2],
                maxY: bbox[f * 4 + 3],
                i: f,
            };
        }

        this.tree.load(items);

        for (const item of items) {
            this.items.push(item);
        }

        if (this._scratch.length < to) {
            this._scratch = new Uint32Array(Math.max(1024, to * 2));
        }
    }

    /** Patch one feature's box after an edit, without rebuilding. */
    update(index) {
        const { bbox } = this.geometry;
        const item = this.items[index];

        this.tree.remove(item);
        item.minX = bbox[index * 4];
        item.minY = bbox[index * 4 + 1];
        item.maxX = bbox[index * 4 + 2];
        item.maxY = bbox[index * 4 + 3];
        this.tree.insert(item);
    }
}

/**
 * The area cull, client side.
 *
 * The server has already applied a threshold for the zoom it was told. This
 * runs again because the client knows its actual viewport and actual device
 * pixel ratio, and because a pan reuses features fetched at a different zoom.
 *
 * @param {Float64Array} area per-feature area in m2
 * @param {Uint32Array} candidates indices from `search`
 * @param {number} threshold square metres below which a feature is not painted
 * @returns {Uint32Array} the survivors, a view onto the candidates buffer,
 *          which this compacts in place
 */
export function cullByArea(area, candidates, threshold) {
    if (threshold <= 0) {
        return candidates;
    }

    // Compacted in place. The survivors are a subset in the same order, so the
    // write index never overtakes the read index, and the alternative is a
    // 144 kB allocation every frame.
    let n = 0;

    for (let c = 0; c < candidates.length; c++) {
        const i = candidates[c];

        // **Zero means "has no area", not "is too small to see".** A point
        // and a line both store 0, so a threshold above zero dropped every one
        // of them at every zoom — they arrived from the server, sat in the
        // typed arrays, and no path was ever built for them. The cull is a
        // statement about polygons and only polygons.
        if (area[i] >= threshold || area[i] === 0) {
            candidates[n++] = i;
        }
    }

    return candidates.subarray(0, n);
}
