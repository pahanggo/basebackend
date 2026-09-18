/**
 * GIS editor entry point.
 *
 * Boots the map, mounts the feature renderer, and keeps the visible layers fed
 * as the viewport moves. The store (S4), the layer tree (S5) and the drawing
 * tools (S6) mount from here later.
 *
 * Leaflet is the global `L`, loaded from the vendored UMD build before this
 * bundle. It is never imported.
 */

import { createRenderer } from './map/renderer.js';
import { ensurePane } from './map/panes.js';
import { SpatialIndex } from './map/spatial-index.js';
import { fetchFeatures } from './data/features.js';

/** @returns {Object} the configuration blob rendered into the page */
function readBootstrap() {
    const el = document.getElementById('gis-bootstrap');

    if (!el) {
        throw new Error('gis: bootstrap blob missing');
    }

    return JSON.parse(el.textContent);
}

/**
 * Keeps one layer's working set in step with the viewport.
 *
 * A read is issued per layer per settled view, and the previous one is aborted
 * — panning across the state would otherwise queue a dozen 50 MB responses
 * nobody is waiting for any more.
 */
class LayerFeed {
    constructor(renderer, config, layer) {
        this.renderer = renderer;
        this.config = config;
        this.layer = layer;
        this.slot = null;
        this.controller = null;
        this.loaded = null;
    }

    /**
     * Whether what is loaded still covers the view.
     *
     * Refetching on every `moveend` is what a naive feed does, and it is
     * unusable: a drag of a few pixels would queue a 6 MB response, and the
     * application's file-based sessions serialise them, so forty small pans
     * become forty queued requests and a frozen tab. Instead the read covers
     * more than the viewport, and only a pan that leaves that area, or a zoom,
     * asks for more.
     */
    covers(bounds, zoom) {
        const loaded = this.loaded;

        return loaded !== null
            && loaded.zoom === zoom
            && loaded.west <= bounds.getWest()
            && loaded.east >= bounds.getEast()
            && loaded.south <= bounds.getSouth()
            && loaded.north >= bounds.getNorth();
    }

    async refresh(map) {
        const bounds = map.getBounds();
        const zoom = map.getZoom();

        if (this.covers(bounds, zoom)) {
            return;
        }

        // A quarter of a viewport on each side. Padding costs area, and area
        // costs features: half a viewport each way doubles both dimensions and
        // so quadruples the response, which measured at 24 MB a layer. A
        // quarter is 2.25x, and ordinary panning still stays inside it.
        const padX = (bounds.getEast() - bounds.getWest()) / 4;
        const padY = (bounds.getNorth() - bounds.getSouth()) / 4;

        const area = {
            west: bounds.getWest() - padX,
            south: bounds.getSouth() - padY,
            east: bounds.getEast() + padX,
            north: bounds.getNorth() + padY,
            zoom,
        };

        if (this.controller) {
            this.controller.abort();
        }

        this.controller = new AbortController();

        try {
            const result = await fetchFeatures({
                apiBase: this.config.apiBase,
                layerId: this.layer.id,
                bbox: [area.west, area.south, area.east, area.north],
                zoom,
                signal: this.controller.signal,
            });

            const entry = {
                geometry: result.geometry,
                index: new SpatialIndex(result.geometry),
                keep: result.keep,
                visible: true,
            };

            if (this.slot === null) {
                this.slot = this.renderer.addGeometry(entry);
            } else {
                this.renderer.replaceGeometry(this.slot, entry);
            }

            this.loaded = area;
            this.timing = result.timing;
            this.cull = result.cull;
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('gis: feature read failed', error);
            }
        }
    }
}

function boot() {
    const config = readBootstrap();
    const container = document.getElementById('gis-map');

    if (!container) {
        return;
    }

    const map = L.map(container, {
        center: [config.view.center[1], config.view.center[0]],
        zoom: config.view.zoom,
        zoomControl: true,
        preferCanvas: true,
    });

    L.tileLayer(config.basemap.url, {
        attribution: config.basemap.attribution,
        maxZoom: config.basemap.maxZoom,
    }).addTo(map);

    const renderer = createRenderer({
        pane: ensurePane(map, 'features', 0),
        minAreaPx: config.capabilities.minAreaPx,
    });

    renderer.addTo(map);

    const feeds = (config.layers || []).map((layer) => new LayerFeed(renderer, config, layer));
    const refreshAll = () => feeds.forEach((feed) => feed.refresh(map));

    map.on('moveend zoomend', refreshAll);
    refreshAll();

    // Docks and toolbars resize the map container without the window changing,
    // so the map has to be told (specification section 17).
    let resizeTimer = null;
    new ResizeObserver(() => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(() => map.invalidateSize(), 100);
    }).observe(container);

    map.on('click', (event) => {
        const hit = renderer.hitTest(event.containerPoint);

        if (hit) {
            console.log('gis: hit', hit);
        }
    });

    window.gis = { map, renderer, feeds, config };
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
