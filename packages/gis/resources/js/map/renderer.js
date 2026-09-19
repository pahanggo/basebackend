/**
 * The feature renderer: one `L.Layer` subclass owning three canvases.
 *
 * Leaflet's own path layers are not used for features. Leaflet creates one DOM
 * node or one `L.Path` per feature and hit-tests by scanning every layer on
 * every pointer move — both linear in feature count, neither survivable
 * against the 164,000 candidates a zoom-12 viewport returns. Leaflet keeps the
 * few dozen interactive objects: vertex handles, in-progress geometry,
 * measurement labels.
 *
 * `L` is the vendored global, never an import.
 */

import { POINT, LINE, projectLng, projectLat } from './geometry.js';
import { cullByArea } from './spatial-index.js';

/**
 * How a path key packs a geometry type and a paint slot into one number.
 *
 * The key of the `paths` map used to be the geometry type alone. A classified
 * layer needs one path per type per class, because each is filled and stroked
 * differently, and a `Map` keyed by a small integer is faster to build and walk
 * than one keyed by a string.
 *
 * Nine bits leaves room for all 256 classes plus the "other" bucket, which is
 * the ceiling the wire format's one-byte class index imposes anyway.
 */
const SLOT_BITS = 9;
const SLOT_MASK = (1 << SLOT_BITS) - 1;

/** An unclassified layer paints everything in slot 0. */
function pathKey(type, slot) {
    return (type << SLOT_BITS) | slot;
}

/**
 * The order to paint one layer's paths in.
 *
 * A `Map` iterates in insertion order, which is the order features happened to
 * arrive — so without this, which sublayer covers which was decided by the
 * read, and changed between reads. Two rules, and the first wins:
 *
 * - **Class order, first on top.** The sublayer rows read top to bottom like
 *   the layer rows above them, so the first class paints last. The "other"
 *   bucket holds the highest slot and therefore ends up underneath, which is
 *   where leftovers belong.
 * - **Then geometry type**, polygons under lines under points, so a boundary
 *   or a marker is never buried by a fill that shares its class.
 */
function paintOrder(paths) {
    return [...paths.keys()].sort((a, b) => {
        const bySlot = (b & SLOT_MASK) - (a & SLOT_MASK);

        return bySlot !== 0 ? bySlot : (b >> SLOT_BITS) - (a >> SLOT_BITS);
    });
}

/** Web Mercator, matching Leaflet's EPSG3857 exactly. */
function worldSize(zoom) {
    return 256 * (2 ** zoom);
}

export const GisRenderer = L.Layer.extend({

    options: {
        // Square pixels below which a feature is not painted. The server has
        // already culled for the zoom it was told; this is the client's own
        // check against its real viewport.
        minAreaPx: 4,

        // The fallback for a layer that carries no style of its own, keyed by
        // geometry type. A layer's own `style` overrides these per layer —
        // see `_paintPaths`.
        styles: {
            1: { stroke: '#2b6cb0', weight: 4, fill: null },
            2: { stroke: '#2b6cb0', weight: 2, fill: null },
            3: { stroke: '#8a6d3b', weight: 1, fill: '#f0ad4e' },
        },
    },

    initialize(options) {
        L.setOptions(this, options);

        /** @type {Array<{geometry: Object, index: Object, visible: boolean}>} */
        this._layers = [];
        this._frame = null;
        this._stats = { candidates: 0, drawn: 0, cullMs: 0, paintMs: 0, vertices: 0 };
    },

    onAdd(map) {
        this._map = map;
        this._container = L.DomUtil.create('div', 'gis-canvases');

        this._feature = this._createCanvas('gis-canvas-feature');
        this._overlay = this._createCanvas('gis-canvas-overlay');
        this._edit = this._createCanvas('gis-canvas-edit');

        this.getPane().appendChild(this._container);

        this._reset();

        return this;
    },

    onRemove() {
        L.DomUtil.remove(this._container);

        return this;
    },

    /**
     * Three canvases, not one.
     *
     * This is a requirement rather than an optimisation: without it, every
     * pointer move during a vertex drag repaints all 13,000 features. S6's
     * editing and S9's selection both depend on it existing.
     */
    _createCanvas(className) {
        const canvas = L.DomUtil.create('canvas', className, this._container);
        canvas.style.position = 'absolute';
        canvas.style.left = '0';
        canvas.style.top = '0';
        canvas.style.pointerEvents = 'none';

        return canvas;
    },

    getEvents() {
        return {
            viewreset: this._reset,
            resize: this._reset,
            moveend: this._onMoveEnd,
            move: this._onMove,
            zoomanim: this._animateZoom,
        };
    },

    /**
     * Register a layer's working set and return the slot that names it.
     *
     * **A slot is stable for the life of the layer.** It has to be: the feed
     * holds it across reads, and the alternative — rebuilding the array
     * whenever the set of drawn layers changes — throws away every other
     * layer's geometry, index and built paths, so hiding one layer refetches
     * all the rest.
     *
     * Removed slots therefore become holes rather than closing up, and a new
     * layer fills the first hole it finds. Paint order is the explicit `order`
     * field, never array position.
     *
     * @param {{geometry: Object, index: Object, order?: number}} layer
     */
    addGeometry(layer) {
        const entry = {
            visible: true, generation: 0, cache: null, opacity: 1,
            order: this._layers.length, ...layer,
        };
        const hole = this._layers.indexOf(null);

        this.schedule();

        if (hole !== -1) {
            this._layers[hole] = entry;

            return hole;
        }

        this._layers.push(entry);

        return this._layers.length - 1;
    },

    /** Drop one layer, leaving the others exactly as they are. */
    removeGeometry(slot) {
        if (this._layers[slot]) {
            this._layers[slot] = null;
            this.schedule();
        }
    },

    /**
     * How opaque this layer is, 0 to 1.
     *
     * Applied as `globalAlpha` in the paint loop — one property set per layer
     * per frame — rather than through a pane. A pane can only fade a
     * TOP-LEVEL tree node, because every vector layer shares one canvas, so a
     * slider on a layer nested in a group did nothing at all.
     */
    setOpacity(slot, opacity) {
        const layer = this._layers[slot];

        if (layer && layer.opacity !== opacity) {
            layer.opacity = opacity;
            this.schedule();
        }
    },

    /**
     * Where this layer sits in the tree: 0 is the first row.
     *
     * Not paint order — the first row paints LAST, so that it paints on top.
     * `_ordered()` does that inversion in one place.
     */
    setOrder(slot, order) {
        const layer = this._layers[slot];

        if (layer && layer.order !== order) {
            layer.order = order;
            this.schedule();
        }
    },

    /**
     * The layers to paint, in paint order, holes skipped.
     *
     * **`order` is the row's position in the tree, and the FIRST row paints on
     * top**, so paint runs from the deepest row upward — descending `order`.
     * This sorted ascending until S5d, which meant the second row in the tree
     * covered the first: on the map that motivated the fix, `Lot` sat above
     * `Gunatanah Semasa` and buried every land-use colour under a sheet of
     * pink. `panes.js` had always reversed for the same reason, and said so;
     * the shared feature canvas simply never agreed with it.
     *
     * The hit test consumes this array backwards and is therefore still
     * correct: last painted is topmost, whichever way this sorts.
     *
     * Sorted per draw rather than kept sorted, because the set is tens of
     * entries and a frame already costs more than this in `setTransform`.
     */
    _ordered() {
        return this._layers.filter(Boolean).sort((a, b) => b.order - a.order);
    },

    /**
     * Change how a layer is painted, without touching what it holds.
     *
     * A colour change must not refetch: the features are already here, the
     * `Path2D` objects are already built, and the only thing that differs is
     * the fill and stroke used to paint them. Rebuilding the layer instead
     * would send a multi-megabyte read over a click on a colour swatch.
     */
    restyleGeometry(slot, style) {
        const layer = this._layers[slot];

        if (!layer) {
            return;
        }

        layer.style = style;
        this.schedule();
    },

    /**
     * How this layer's features map onto paint slots.
     *
     * This one DOES rebuild the paths, because it decides which path each
     * feature goes into: splitting a layer by a different field, or adding a
     * class, moves features between paths and a `Path2D` cannot give a subpath
     * back. Bumping the generation is what invalidates the cache.
     *
     * Reclassifying is a deliberate act a few times a session. Recolouring one
     * class is a drag of a slider, and goes through `repaintClasses` instead,
     * which rebuilds nothing.
     *
     * @param {Uint8Array|null} slotOf dictionary index to paint slot, or null
     *        to stop classifying this layer
     */
    reclassifyGeometry(slot, slotOf) {
        const layer = this._layers[slot];

        if (!layer) {
            return;
        }

        layer.slotOf = slotOf;
        layer.generation += 1;
        this.schedule();
    },

    /**
     * The same map, without invalidating the paths.
     *
     * For use WHILE a read is streaming. The dictionary grows as frames arrive,
     * so the map is replaced before each chunk is appended — and the chunk is
     * then built with it, while the chunks already appended were built with a
     * map that agreed about every value they contained. Bumping the generation
     * here instead would rebuild the whole path on every frame, which measured
     * at 1.9 s for one read when a previous version of this file did it.
     */
    setSlotMap(slot, slotOf) {
        const layer = this._layers[slot];

        if (layer) {
            layer.slotOf = slotOf;
        }
    },

    /**
     * The colours, opacity and visibility of each paint slot.
     *
     * A repaint and nothing more: the paths are already built and which one a
     * feature is in has not changed. This is the path a colour swatch, an
     * opacity drag and a sublayer checkbox all take, and it is why none of
     * them costs a feature read.
     *
     * @param {Array<{style: Object, opacity: number, visible: boolean}>|null} paints
     */
    repaintClasses(slot, paints) {
        const layer = this._layers[slot];

        if (!layer) {
            return;
        }

        layer.paints = paints;
        this.schedule();
    },

    /** Swap one layer's working set, keeping its position in draw order. */
    replaceGeometry(slot, layer) {
        const previous = this._layers[slot];

        this._layers[slot] = {
            visible: true,
            generation: (previous?.generation ?? 0) + 1,
            cache: null,
            // Kept, or a layer would jump to the bottom of the stack every
            // time its working set was swapped.
            order: previous?.order ?? this._layers.length,
            opacity: previous?.opacity ?? 1,
            // Kept for the same reason. These say how the layer PAINTS, and
            // swapping which features it holds does not change that — but the
            // working set is swapped after every eviction, which is after every
            // pan that reads. Dropping them here made a classified layer revert
            // to its base colour the moment the map moved.
            slotOf: previous?.slotOf ?? null,
            paints: previous?.paints ?? null,
            ...layer,
        };

        this.schedule();
    },

    /**
     * Extend one layer's working set with the features a streamed read just
     * appended, without rebuilding what is already painted.
     *
     * The response arrives 100 features at a time, so replacing the layer per
     * chunk would rebuild a growing path 130 times over one read — quadratic,
     * and measurably so: the cold build of 40,000 features is 76 ms. Instead
     * the new features are added to the `Path2D` objects already built, which
     * costs only what they contain.
     *
     * `Path2D` has no way to remove a subpath, so this is only valid while the
     * cache it extends is still the right one. Where it is not — a zoom during
     * the read, a threshold change — the whole set is invalidated and the next
     * draw rebuilds it, which is the correct answer and happens at most once.
     *
     * @param {number} slot
     * @param {{from: number, to: number}} chunk
     */
    appendGeometry(slot, { from, to }) {
        const layer = this._layers[slot];

        if (!layer || !this._map) {
            return;
        }

        const cache = layer.cache;

        if (!cache || cache.generation !== layer.generation || cache.zoom !== this._map.getZoom()) {
            layer.cache = null;
            this.schedule();

            return;
        }

        const added = new Uint32Array(to - from);

        for (let f = from; f < to; f++) {
            added[f - from] = f;
        }

        const kept = cullByArea(layer.geometry.area, added, cache.threshold);
        const built = this._buildPaths(layer, kept, worldSize(cache.zoom), cache.originX, cache.originY, cache.zoom, cache.paths);

        cache.candidates += to - from;
        cache.drawn += kept.length;
        cache.vertices += built.vertices;

        this.schedule();
    },

    /**
     * Forget every layer.
     *
     * For switching maps, where nothing carries over. **Not for a visibility
     * change** — see `removeGeometry`.
     */
    clearGeometry() {
        this._layers = [];
        this.schedule();
    },

    stats() {
        return { ...this._stats };
    },

    _onMove() {
        this.schedule();
    },

    _onMoveEnd() {
        this._reset();
    },

    /**
     * Pin the canvases to the viewport's top-left corner.
     *
     * This has to happen on **every** draw, not only on `moveend`. The canvases
     * live inside Leaflet's map pane, and Leaflet pans by moving that pane, so
     * they are already carried along with the basemap. The paint then shifts
     * the cached path by the same distance again — and the features slide at
     * twice the speed of the tiles under them.
     *
     * Repositioning the container to the current top-left cancels the pane's
     * movement, leaving the transform as the only thing that moves the
     * features. Drawing is then in container coordinates, so a projected world
     * pixel becomes a canvas pixel by subtracting one origin.
     */
    _position() {
        L.DomUtil.setPosition(this._container, this._map.containerPointToLayerPoint([0, 0]));
    },

    /** Size the canvases to the viewport. */
    _reset() {
        if (!this._map) {
            return;
        }

        const size = this._map.getSize();

        this._position();

        const ratio = window.devicePixelRatio || 1;

        for (const canvas of [this._feature, this._overlay, this._edit]) {
            canvas.width = size.x * ratio;
            canvas.height = size.y * ratio;
            canvas.style.width = `${size.x}px`;
            canvas.style.height = `${size.y}px`;
        }

        this._ratio = ratio;
        this._size = size;
        this.schedule();
    },

    /** Follow Leaflet's animated transform rather than repainting mid-zoom. */
    _animateZoom(event) {
        const scale = this._map.getZoomScale(event.zoom, this._map.getZoom());
        const offset = this._map._latLngToNewLayerPoint(
            this._map.containerPointToLatLng([0, 0]),
            event.zoom,
            event.center,
        );

        L.DomUtil.setTransform(this._container, offset, scale);
    },

    /**
     * Coalesce every invalidation in a frame into one paint.
     */
    schedule() {
        if (this._frame !== null) {
            return;
        }

        this._frame = requestAnimationFrame(() => {
            this._frame = null;
            this.draw();
        });
    },

    /**
     * Square metres per `minAreaPx` square pixels, here and now.
     */
    areaThreshold() {
        const centre = this._map.getCenter();
        const metresPerPixel = 156543.03392 * Math.cos(centre.lat * Math.PI / 180)
            / (2 ** this._map.getZoom());

        return metresPerPixel * metresPerPixel * this.options.minAreaPx;
    },

    draw() {
        if (!this._map || !this._size) {
            return;
        }

        this._position();

        const context = this._feature.getContext('2d');
        const zoom = this._map.getZoom();
        const scale = worldSize(zoom);
        const pixelOrigin = this._map.getPixelOrigin();
        const topLeft = this._map.containerPointToLayerPoint([0, 0]);
        const originX = pixelOrigin.x + topLeft.x;
        const originY = pixelOrigin.y + topLeft.y;
        const threshold = this.areaThreshold();

        context.setTransform(this._ratio, 0, 0, this._ratio, 0, 0);
        context.clearRect(0, 0, this._size.x, this._size.y);

        let candidates = 0;
        let drawn = 0;
        let vertices = 0;
        let buildMs = 0;

        const paintStart = performance.now();

        for (const layer of this._ordered()) {
            if (!layer.visible) {
                continue;
            }

            const cache = this._ensurePath(layer, zoom, scale, threshold, originX, originY);

            candidates += cache.candidates;
            drawn += cache.drawn;
            vertices += cache.vertices;
            buildMs += cache.buildMs;

            // The path is built once in world pixels and then translated. A pan
            // at constant zoom changes only the offset, so panning costs a
            // transform and a fill rather than rebuilding 100,000 vertices —
            // which is the difference between 1 ms and 40 ms a frame, and
            // therefore between meeting the frame-rate budget and not.
            const dx = cache.originX - originX;
            const dy = cache.originY - originY;

            context.setTransform(this._ratio, 0, 0, this._ratio, dx * this._ratio, dy * this._ratio);

            this._paintPaths(context, cache.paths, layer.style, layer.opacity ?? 1, layer.paints ?? null);
        }

        context.setTransform(this._ratio, 0, 0, this._ratio, 0, 0);

        this._stats = {
            candidates,
            drawn,
            vertices,
            buildMs,
            cullMs: buildMs,
            paintMs: performance.now() - paintStart,
        };
    },

    /**
     * The built paths for one layer, rebuilt only when they cannot be reused.
     *
     * Zoom changes the projection scale and the cull threshold, and a new
     * response replaces the working set; anything else — a pan, a resize, a
     * selection — leaves the paths valid.
     */
    _ensurePath(layer, zoom, scale, threshold, originX, originY) {
        const cache = layer.cache;

        if (cache && cache.zoom === zoom && cache.threshold === threshold && cache.generation === layer.generation) {
            return cache;
        }

        const started = performance.now();

        // Everything the layer holds. The feed only ever loads the viewport,
        // and the server has already culled it, so this is the working set
        // rather than the whole 672,000.
        const found = layer.index.search(0, 0, 1, 1);
        const kept = cullByArea(layer.geometry.area, found, threshold);

        const built = this._buildPaths(layer, kept, scale, originX, originY, zoom);

        layer.cache = {
            ...built,
            zoom,
            threshold,
            generation: layer.generation,
            originX,
            originY,
            candidates: found.length,
            drawn: kept.length,
            buildMs: performance.now() - started,
        };

        return layer.cache;
    },

    /**
     * Accumulate the surviving features into one `Path2D` per style.
     *
     * Coordinates are stored projected and normalised, so a world pixel is one
     * multiply and one subtract. The subtract keeps the numbers near the
     * viewport rather than near 16 million, which matters because canvas paths
     * hold their points as 32-bit floats: at zoom 18 an unshifted coordinate
     * has lost the precision to place a vertex on the right pixel.
     *
     * `paths` may be an existing set, which is how a streamed chunk is added to
     * what is already drawn. The origin and scale must then be the ones the
     * path was built with, or the new features land offset from the old.
     */
    _buildPaths(layer, kept, scale, originX, originY, zoom, paths = new Map()) {
        const { coords, ringStarts, featStarts, types } = layer.geometry;
        const classes = layer.slotOf ? layer.geometry.classes : null;
        const slotOf = classes ? layer.slotOf : null;
        let vertices = 0;

        for (let k = 0; k < kept.length; k++) {
            const f = kept[k];
            const type = types[f];

            // One path per geometry type per paint slot. The same vertices go
            // into more paths when a layer is classified, never more vertices,
            // so the build costs what it always did — and a category can then
            // be recoloured or hidden by touching the paint table alone.
            const key = pathKey(type, slotOf ? slotOf[classes[f]] : 0);

            let path = paths.get(key);

            if (path === undefined) {
                path = new Path2D();
                paths.set(key, path);
            }

            for (let r = featStarts[f]; r < featStarts[f + 1]; r++) {
                const start = ringStarts[r];
                const end = ringStarts[r + 1];

                if (type === POINT) {
                    const x = coords[start * 2] * scale - originX;
                    const y = coords[start * 2 + 1] * scale - originY;
                    path.moveTo(x + 3, y);
                    path.arc(x, y, 3, 0, Math.PI * 2);
                    vertices++;
                    continue;
                }

                let moved = false;

                for (let v = start; v < end; v++) {
                    const x = coords[v * 2] * scale - originX;
                    const y = coords[v * 2 + 1] * scale - originY;

                    if (moved) {
                        path.lineTo(x, y);
                    } else {
                        path.moveTo(x, y);
                        moved = true;
                    }

                    vertices++;
                }

                // No `closePath()`. It is not needed — every polygon ring
                // arrives with its first vertex repeated at the end, so the
                // subpath already closes itself — and it is ruinously
                // expensive: 16,180 calls on one accumulating Path2D measured
                // at 2,151 ms of a 2,177 ms repaint, because each call costs
                // time proportional to the whole path built so far.
                void type;
            }
        }

        return { paths, vertices };
    },

    /**
     * One fill and one stroke per geometry type, never per feature.
     *
     * The layer's own style wins over the per-type default, key by key, so a
     * layer that sets only a fill colour keeps the default stroke width rather
     * than losing it. A null fill or stroke is a deliberate "do not paint
     * this", which is why the merge cannot simply drop nulls.
     *
     * **The layer's opacity is the only alpha.** `style.fillOpacity` used to
     * multiply it, which meant two controls for one visible property and a
     * slider that could not reach opaque: the imported layers carry 0.15, so
     * 100% opacity still painted at 15% and looked broken. One slider, one
     * number, and what it says is what you see.
     *
     * @param {Object} [layerStyle] the layer's `style`, or undefined for the defaults
     * @param {number} [opacity] the layer's effective opacity, 0 to 1
     */
    _paintPaths(context, paths, layerStyle = null, opacity = 1, paints = null) {
        for (const key of paintOrder(paths)) {
            const path = paths.get(key);
            const type = key >> SLOT_BITS;
            const paint = paints ? paints[key & SLOT_MASK] : null;

            // A hidden class is skipped rather than painted transparent: alpha
            // zero still costs a fill over every one of its features.
            if (paint && !paint.visible) {
                continue;
            }

            const own = paint ? paint.style : layerStyle;
            const style = own
                ? { ...this.options.styles[type], ...own }
                : this.options.styles[type];

            // The class's own alpha times the layer's. Opacity has always
            // multiplied down the tree — a group through a layer — and a class
            // is one more level of that chain, not the second alpha over one
            // object that section 10 forbids.
            const alpha = paint ? opacity * paint.opacity : opacity;

            if (style.fill) {
                context.globalAlpha = alpha;
                context.fillStyle = style.fill;
                context.fill(path, 'evenodd');
            }

            if (style.stroke) {
                context.globalAlpha = alpha;
                context.strokeStyle = style.stroke;
                context.lineWidth = style.weight;
                context.stroke(path);
            }

            context.globalAlpha = 1;
        }
    },

    /**
     * What is under this point.
     *
     * The index narrows 13,000 features to a handful; the exact test runs only
     * on those. Anything linear in feature count here would be felt on every
     * pointer move.
     *
     * @returns {{layer: number, feature: number, id: number}|null}
     */
    hitTest(containerPoint, tolerancePx = 10) {
        const map = this._map;
        const latLng = map.containerPointToLatLng(containerPoint);
        const x = projectLng(latLng.lng);
        const y = projectLat(latLng.lat);

        // One pixel is `1 / scale` in projected units, at every latitude.
        const tolerance = tolerancePx / worldSize(map.getZoom());
        const threshold = this.areaThreshold();

        // Topmost first, which is the reverse of paint order: a click belongs
        // to the layer drawn last, not the one underneath it.
        const ordered = this._ordered();

        for (let l = ordered.length - 1; l >= 0; l--) {
            const layer = ordered[l];

            if (!layer.visible) {
                continue;
            }

            const found = layer.index.search(x - tolerance, y - tolerance, x + tolerance, y + tolerance);

            for (let h = found.length - 1; h >= 0; h--) {
                const f = found[h];

                if (layer.geometry.area[f] < threshold) {
                    continue;
                }

                if (this._contains(layer.geometry, f, x, y, tolerance)) {
                    return { layer: l, feature: f, id: layer.geometry.ids[f] };
                }
            }
        }

        return null;
    },

    /**
     * Point in polygon by ray casting, or distance to segment for lines.
     *
     * Everything here is in projected units, which is what makes a pixel
     * tolerance meaningful: in longitude and latitude it would be a different
     * distance at every latitude.
     */
    _contains(geometry, f, px, py, tolerance) {
        const { coords, ringStarts, featStarts, types } = geometry;
        const type = types[f];
        let inside = false;

        for (let r = featStarts[f]; r < featStarts[f + 1]; r++) {
            const start = ringStarts[r];
            const end = ringStarts[r + 1];

            if (type === POINT) {
                const dx = coords[start * 2] - px;
                const dy = coords[start * 2 + 1] - py;

                if (Math.sqrt(dx * dx + dy * dy) <= tolerance) {
                    return true;
                }

                continue;
            }

            if (type === LINE) {
                for (let v = start; v < end - 1; v++) {
                    if (this._nearSegment(coords, v, v + 1, px, py, tolerance)) {
                        return true;
                    }
                }

                continue;
            }

            for (let v = start, w = end - 1; v < end; w = v++) {
                const yi = coords[v * 2 + 1];
                const yj = coords[w * 2 + 1];

                if ((yi > py) === (yj > py)) {
                    continue;
                }

                const xi = coords[v * 2];
                const xj = coords[w * 2];

                if (px < (xj - xi) * (py - yi) / (yj - yi) + xi) {
                    inside = !inside;
                }
            }
        }

        return inside;
    },

    _nearSegment(coords, a, b, px, py, tolerance) {
        const ax = coords[a * 2];
        const ay = coords[a * 2 + 1];
        const bx = coords[b * 2];
        const by = coords[b * 2 + 1];

        const dx = bx - ax;
        const dy = by - ay;
        const lengthSquared = dx * dx + dy * dy;

        let t = lengthSquared === 0 ? 0 : ((px - ax) * dx + (py - ay) * dy) / lengthSquared;
        t = Math.max(0, Math.min(1, t));

        const ox = ax + t * dx - px;
        const oy = ay + t * dy - py;

        return Math.sqrt(ox * ox + oy * oy) <= tolerance;
    },
});

export function createRenderer(options) {
    return new GisRenderer(options);
}
