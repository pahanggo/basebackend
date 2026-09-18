/**
 * GIS editor entry point.
 *
 * Boots the map, mounts the feature renderer, keeps the visible layers fed as
 * the viewport moves, and stands up the store. The layer tree (S5) and the
 * drawing tools (S6) mount from here later and are what will first commit to
 * the store — until then it exists, is wired to the server, and holds nothing.
 *
 * Leaflet is the global `L`, loaded from the vendored UMD build before this
 * bundle. It is never imported.
 */

import { createRenderer } from './map/renderer.js';
import { ensurePane } from './map/panes.js';
import { SpatialIndex } from './map/spatial-index.js';
import { projectLng, projectLat } from './map/geometry.js';
import { fetchFeatures } from './data/features.js';
import { FeatureAccumulator } from './data/accumulator.js';
import { Store } from './store/store.js';
import { SyncQueue } from './store/sync.js';
import { reconcile } from './store/commands/index.js';
import { configureHttp, getJson } from './lib/http.js';
import { afterKey } from './lib/sort-key.js';
import { MapBrowser } from './ui/map-browser.js';
import { LayerLibrary } from './ui/layer-library.js';

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
        this.entry = null;
        this.accumulator = null;
        this.capped = false;
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

        // A pan overlaps what is already held: a quarter-viewport pan leaves 83%
        // of the new padded area in memory. So the read declares what it holds
        // and the server leaves those features out, and the response carries
        // only the new edge rather than the whole area again.
        //
        // Only at constant zoom. Zoom moves the area threshold and, in the
        // readable encoding, the geometry column too, so a zoom change makes
        // everything held the wrong resolution and the wrong selection.
        const additive = this.loaded !== null
            && this.loaded.zoom === zoom
            && this.slot !== null
            && this.entry !== null
            // A capped response dropped features inside the box it covers, so
            // excluding that box next time would lose them permanently.
            && !this.capped;

        if (!additive) {
            this.accumulator = new FeatureAccumulator();
            this.entry = null;
        }

        try {
            const result = await fetchFeatures({
                apiBase: this.config.apiBase,
                layerId: this.layer.id,
                bbox: [area.west, area.south, area.east, area.north],
                zoom,
                signal: this.controller.signal,
                into: this.accumulator,
                held: additive
                    ? [this.loaded.west, this.loaded.south, this.loaded.east, this.loaded.north]
                    : null,
                onChunk: ({ geometry, from, to }) => {
                    if (this.entry === null) {
                        this.entry = { geometry, index: new SpatialIndex(geometry), visible: true };

                        if (this.slot === null) {
                            this.slot = this.renderer.addGeometry(this.entry);
                        } else {
                            this.renderer.replaceGeometry(this.slot, this.entry);
                        }

                        return;
                    }

                    this.entry.index.append(from, to);
                    this.renderer.appendGeometry(this.slot, { from, to });
                },
            });

            this.loaded = area;
            this.timing = result.timing;
            this.cull = result.cull;
            this.capped = result.cull.capped === true;
            this.evict(area);
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('gis: feature read failed', error);
            }
        }
    }

    /**
     * Drop everything outside the area just read, so that what is held is
     * exactly what `loaded` claims.
     *
     * **That equality is what makes the exclusion box sound.** The next read
     * tells the server "I hold everything in this box"; if the client were also
     * holding leftovers from an older area, a pan back across them would ask
     * for features it already had and append them twice — and two identical
     * rings in one `Path2D` filled `evenodd` cancel out, so the duplicate shows
     * as a hole rather than as extra ink.
     *
     * The alternative is to keep the leftovers as a cache and deduplicate by id
     * on the way in. That trades a bounded, O(n) pass here for an unbounded
     * working set, and the arrays are the largest thing the client owns — 30 MB
     * at 40,000 features, so ten screens of panning would be 300 MB.
     *
     * The index works in projected units, so the area is projected to match.
     * Projection inverts latitude, which is why north becomes the smaller
     * bound.
     */
    evict(area) {
        if (this.entry === null || this.accumulator === null) {
            return;
        }

        const west = projectLng(area.west);
        const east = projectLng(area.east);
        const north = projectLat(area.north);
        const south = projectLat(area.south);

        const bbox = this.entry.geometry.bbox;
        const dropped = this.accumulator.compact(
            (f) => bbox[f * 4] <= east
                && bbox[f * 4 + 2] >= west
                && bbox[f * 4 + 1] <= south
                && bbox[f * 4 + 3] >= north,
        );

        if (dropped === 0) {
            return;
        }

        // Every feature index moved, so the index is rebuilt rather than
        // patched, and the renderer's paths are invalidated — a `Path2D` cannot
        // give a subpath back.
        this.entry.index = new SpatialIndex(this.entry.geometry);
        this.renderer.replaceGeometry(this.slot, this.entry);
    }
}

/**
 * The store and its outbound queue for one map.
 *
 * Both are rebuilt when the map changes, because both are per map: the undo
 * stack does not survive a switch (section 16), and a queue still holding
 * commands for the previous map would post them against a map that is no
 * longer open.
 *
 * The `clientId` is NOT rebuilt. It is half of the idempotency key and has to
 * be stable for the life of the tab, or a retry after a switch would look like
 * new work.
 */
function createStore(config, mapId) {
    const sync = mapId === null ? null : new SyncQueue({
        apiBase: config.apiBase,
        mapId,
        clientId: config.clientId,
        csrfToken: config.csrfToken,
        onApplied: (body) => {
            reconcile(store.state, body.applied);
            store.emit(['features', 'layers', 'measurements']);
        },
        onConflict: (problem) => {
            // S5b opens the conflict panel here. Until it exists the queue is
            // paused and the commands are kept, which is the safe half of the
            // behaviour: nothing is lost, the user is simply not yet told.
            console.warn('gis: version conflict', problem);
        },
    });

    return { store: new Store({ sync }), sync };
}

/**
 * Put a map's bootstrap payload into the store.
 *
 * The layer and its placement arrive flattened, because that is how the tree
 * renders them, and they are stored apart — two ids, two versions. Collapsing
 * them here is the mistake the API shape exists to prevent: a rename guards on
 * the layer's version, a reorder on the placement's.
 */
function hydrate(store, bootstrap) {
    store.state.map = { id: bootstrap.id, name: bootstrap.name, version: bootstrap.version, role: bootstrap.role };
    store.state.layers = {};
    store.state.placements = {};
    store.state.tree = [];

    for (const entry of bootstrap.layers) {
        store.state.layers[entry.layerId] = {
            id: entry.layerId,
            name: entry.name,
            kind: entry.kind,
            locked: entry.locked,
            style: entry.style,
            attrSchema: entry.attrSchema,
            featureCount: entry.featureCount,
            ownerMapId: entry.ownerMapId,
            ownedHere: entry.ownedHere,
            shared: entry.shared,
            version: entry.layerVersion,
        };

        store.state.placements[entry.placementId] = {
            id: entry.placementId,
            layerId: entry.layerId,
            parentId: entry.parentId,
            sortKey: entry.sortKey,
            visible: entry.visible,
            opacity: entry.opacity,
            minZoom: entry.minZoom,
            maxZoom: entry.maxZoom,
            access: entry.access,
            version: entry.placementVersion,
        };

        store.state.tree.push(entry.placementId);
    }
}

/** The vector layers this map shows, in tree order. S5b replaces this with the tree. */
function visibleVectorLayers(store) {
    return store.state.tree
        .map((id) => store.state.placements[id])
        .filter((placement) => placement.visible)
        .map((placement) => store.state.layers[placement.layerId])
        .filter((layer) => layer && layer.kind === 'vector');
}

/**
 * The editor: one map open at a time, and the machinery to change which.
 */
class Editor {
    constructor(config, map) {
        this.config = config;
        this.map = map;
        this.feeds = [];
        this.bootstrap = null;
        this.store = null;
        this.sync = null;
        this.basemapLayer = null;

        this.browser = new MapBrowser({
            apiBase: config.apiBase,
            strings: config.strings,
            clientId: config.clientId,
            currentMapId: () => this.bootstrap?.id ?? null,
            canRestore: () => config.canRestore === true,
            onRestore: (id) => this.restoreMap(id),
            onLoad: (id, bootstrap) => this.open(id, bootstrap),
        });

        this.library = new LayerLibrary({
            apiBase: config.apiBase,
            strings: config.strings,
            currentMapId: () => this.bootstrap?.id ?? null,
            onPlace: (layerId, access) => this.place(layerId, access),
        });
    }

    /**
     * Switch to a map.
     *
     * **The queue is flushed before anything else changes.** Switching with
     * unsynced commands in flight would strand them against a map that is no
     * longer open; if the flush fails the switch is blocked and the changes are
     * kept, never discarded silently (specification section 8).
     */
    async open(mapId, bootstrap = null) {
        if (this.sync && this.sync.queue.length > 0) {
            const flushed = await this.sync.flush().catch(() => null);

            if (flushed === null || this.sync.queue.length > 0) {
                window.alert(this.config.strings.unsyncedChanges);

                return false;
            }
        }

        this.bootstrap = bootstrap || await getJson(`${this.config.apiBase}/maps/${mapId}`);

        const { store, sync } = createStore(this.config, this.bootstrap.id);

        this.store = store;
        this.sync = sync;

        hydrate(this.store, this.bootstrap);
        this.applyBasemap();
        this.rebuildFeeds();

        return true;
    }

    applyBasemap() {
        const basemaps = this.bootstrap.basemaps;
        const chosen = this.bootstrap.viewState?.basemap || basemaps.default;

        // The template comes from the server, never assembled here: its path is
        // `{x}/{y}/{z}`, which is not Leaflet's default order, so building one
        // locally would silently request the wrong tiles.
        const url = basemaps.urlTemplate.replace(/\/tiles\/[^/]+\//, `/tiles/${chosen}/`);

        if (this.basemapLayer) {
            this.map.removeLayer(this.basemapLayer);
        }

        this.basemapLayer = L.tileLayer(url, {
            attribution: basemaps.attribution,
            maxZoom: 20,
            crossOrigin: 'anonymous',
        }).addTo(this.map);
    }

    rebuildFeeds() {
        this.renderer.clearGeometry();

        this.feeds = visibleVectorLayers(this.store)
            .map((layer) => new LayerFeed(this.renderer, this.config, layer));

        this.refresh();
    }

    refresh() {
        this.feeds.forEach((feed) => feed.refresh(this.map));
    }

    /** Place a library layer in this map: one command, one row, nothing copied. */
    async place(layerId, access) {
        const keys = this.store.state.tree.map((id) => this.store.state.placements[id]?.sortKey).filter(Boolean);

        await this.sendCommands([{
            op: 'layer.share',
            layerId,
            mapId: this.bootstrap.id,
            access,
            sortKey: afterKey(keys.length === 0 ? null : keys[keys.length - 1]),
        }]);

        await this.open(this.bootstrap.id);
    }

    restoreMap(id) {
        return this.sendCommands([{ op: 'map.restore', id }]);
    }

    /**
     * Commands that are not the result of a store mutation.
     *
     * Placing a layer and restoring a map both change server state the client
     * has no optimistic copy of, so they go straight out rather than through
     * `commit()`. Anything the user can undo goes through the store.
     */
    sendCommands(commands) {
        return fetch(`${this.config.apiBase}/maps/${this.bootstrap.id}/commands`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': this.config.csrfToken,
            },
            body: JSON.stringify({
                clientId: this.config.clientId,
                seq: this.sync ? (this.sync.seq += 1) : 1,
                mapVersion: this.bootstrap.version,
                commands,
            }),
        }).then(async (response) => {
            const body = await response.json();

            if (!response.ok) {
                const error = new Error(body.detail || 'Command failed');

                error.problem = body;

                throw error;
            }

            return body;
        });
    }
}

async function boot() {
    const config = readBootstrap();
    const container = document.getElementById('gis-map');

    if (!container) {
        return;
    }

    configureHttp({ token: config.csrfToken });

    const view = config.map?.viewState || config.view;

    const map = window._map = L.map(container, {
        center: [view.center[1], view.center[0]],
        zoom: view.zoom,
        zoomControl: true,
        preferCanvas: true,
    });

    const renderer = createRenderer({
        pane: ensurePane(map, 'features', 0),
        minAreaPx: config.capabilities.minAreaPx,
    });

    renderer.addTo(map);

    const editor = new Editor(config, map);

    editor.renderer = renderer;

    map.on('moveend zoomend', () => editor.refresh());

    // Docks and toolbars resize the map container without the window changing,
    // so the map has to be told (specification section 17).
    let resizeTimer = null;
    new ResizeObserver(() => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(() => map.invalidateSize(), 100);
    }).observe(container);

    document.getElementById('gis-open-maps')?.addEventListener('click', () => editor.browser.open());
    document.getElementById('gis-add-layer')?.addEventListener('click', () => editor.library.open());

    window.gis = { map, renderer, editor, config };

    if (config.map === null) {
        // No map to open is the one state with nothing to render, so the
        // browser opens itself rather than leaving a blank canvas.
        await editor.browser.open();

        return;
    }

    await editor.open(config.map.id, config.map);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
