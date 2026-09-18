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

        styles: {
            1: { stroke: '#2b6cb0', weight: 4, fill: null },
            2: { stroke: '#2b6cb0', weight: 2, fill: null },
            3: { stroke: '#8a6d3b', weight: 1, fill: '#f0ad4e', fillOpacity: 0.15 },
        },
    },

    initialize(options) {
        L.setOptions(this, options);

        /** @type {Array<{geometry: Object, index: Object, keep: Uint8Array|null, visible: boolean}>} */
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

    /** @param {{geometry: Object, index: Object, keep?: Uint8Array}} layer */
    addGeometry(layer) {
        this._layers.push({ keep: null, visible: true, generation: 0, cache: null, ...layer });
        this.schedule();

        return this._layers.length - 1;
    },

    /** Swap one layer's working set, keeping its position in draw order. */
    replaceGeometry(slot, layer) {
        const generation = (this._layers[slot]?.generation ?? 0) + 1;

        this._layers[slot] = { keep: null, visible: true, generation, cache: null, ...layer };
        this.schedule();
    },

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
     * Size the canvases to the viewport and pin them to its top-left corner.
     *
     * Drawing then happens in container coordinates, so a projected world pixel
     * becomes a canvas pixel by subtracting one origin.
     */
    _reset() {
        if (!this._map) {
            return;
        }

        const size = this._map.getSize();
        const topLeft = this._map.containerPointToLayerPoint([0, 0]);

        L.DomUtil.setPosition(this._container, topLeft);

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

        for (const layer of this._layers) {
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

            this._paintPaths(context, cache.paths);
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
     */
    _buildPaths(layer, kept, scale, originX, originY, zoom) {
        const { coords, ringStarts, featStarts, types } = layer.geometry;
        const keep = zoom < 16 ? layer.keep : null;
        const paths = new Map();
        let vertices = 0;

        for (let k = 0; k < kept.length; k++) {
            const f = kept[k];
            const type = types[f];

            let path = paths.get(type);

            if (path === undefined) {
                path = new Path2D();
                paths.set(type, path);
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
                    if (keep !== null && keep[v] === 0) {
                        continue;
                    }

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

    /** One fill and one stroke per style, never per feature. */
    _paintPaths(context, paths) {
        for (const [type, path] of paths) {
            const style = this.options.styles[type];

            if (style.fill) {
                context.globalAlpha = style.fillOpacity ?? 1;
                context.fillStyle = style.fill;
                context.fill(path, 'evenodd');
                context.globalAlpha = 1;
            }

            if (style.stroke) {
                context.strokeStyle = style.stroke;
                context.lineWidth = style.weight;
                context.stroke(path);
            }
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

        for (let l = this._layers.length - 1; l >= 0; l--) {
            const layer = this._layers[l];

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
