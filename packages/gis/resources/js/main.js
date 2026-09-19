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
import { ensurePane, syncPanes } from './map/panes.js';
import { SpatialIndex } from './map/spatial-index.js';
import { projectLng, projectLat } from './map/geometry.js';
import { fetchFeatures } from './data/features.js';
import { FeatureAccumulator } from './data/accumulator.js';
import { Store } from './store/store.js';
import { SyncQueue } from './store/sync.js';
import { reconcile } from './store/commands/index.js';
import { configureHttp, getJson, patchJson, putJson } from './lib/http.js';
import { afterKey } from './lib/sort-key.js';
import { SeqCounter } from './lib/seq.js';
import { LayerTree } from './ui/layer-tree.js';
import { ControlPanel } from './ui/control-panel.js';
import { enableTooltips } from './ui/tooltips.js';
import { notify, confirmAction } from './ui/confirm.js';
import { effectiveVisible, effectiveOpacity, buildIndex, childrenOf } from './ui/tree-model.js';
import { Activity } from './ui/activity.js';
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
    constructor(renderer, config, layer, activity, order = 0, opacity = 1) {
        this.renderer = renderer;
        this.config = config;
        this.layer = layer;
        this.activity = activity;
        this.order = order;
        this.opacity = opacity;
        this.slot = null;
        this.controller = null;
        this.loaded = null;
        this.entry = null;
        this.accumulator = null;
        this.capped = false;
    }

    /**
     * Give up this layer's slot and stop whatever it has in flight.
     *
     * Called when a layer stops being drawn — hidden, isolated away, removed
     * from the map. The slot becomes a hole the next layer can fill; every
     * other layer is untouched, which is the whole point.
     */
    release() {
        if (this.controller) {
            this.controller.abort();
            this.controller = null;
        }

        if (this.slot !== null) {
            this.renderer.removeGeometry(this.slot);
            this.slot = null;
        }

        this.entry = null;
        this.loaded = null;
        this.accumulator = null;
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

        this.activity.start();

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
                        this.entry = { geometry, index: new SpatialIndex(geometry), visible: true, style: this.layer.style };

                        if (this.slot === null) {
                            this.slot = this.renderer.addGeometry({ ...this.entry, order: this.order, opacity: this.opacity });
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
        } finally {
            // In `finally`, so an aborted read releases the indicator too —
            // panning aborts the previous read on every settled view.
            this.activity.stop();
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
/**
 * The store and its outbound queue, which reference each other.
 *
 * The store is built first and the queue given a getter rather than the store
 * itself, because `onApplied` runs long after this function returns and needs
 * whatever the store is *then*. Closing over a `const` declared below its own
 * use is a `ReferenceError` at the moment the server first confirms a batch —
 * which is to say, in production and not in any test that never reaches the
 * network.
 */
function createStore(config, mapId, seq, onSyncPaused = null) {
    const store = new Store();

    const sync = mapId === null ? null : new SyncQueue({
        apiBase: config.apiBase,
        mapId,
        clientId: config.clientId,
        seq,
        csrfToken: config.csrfToken,
        onApplied: (body) => {
            reconcile(store.state, body.applied);
            store.emit(['features', 'layers', 'measurements', 'tree']);
        },
        onConflict: (problem) => {
            // The queue pauses and keeps the commands, which is the safe half:
            // nothing is lost. **But it must not do that silently.** A paused
            // queue stops sending everything, so every later change looks
            // applied, survives until a reload and then is not there — and the
            // only sign was a line in the console. The resolution panel that
            // uses `store/conflicts.js` is S6's, alongside the editing that
            // makes a real conflict likely; until then the user is at least
            // told, and given the one action that recovers it.
            console.warn('gis: version conflict', problem);
            onSyncPaused?.(problem);
        },
    });

    store.sync = sync;

    return { store, sync };
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
            extent: entry.extent ?? null,
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

/**
 * The vector layers this map actually draws, in tree order.
 *
 * Effective visibility, not the placement's own flag: a layer inside a hidden
 * group, or outside its zoom band, is not drawn and must not be fetched for
 * either. Isolate is applied here too, because it hides layers without
 * changing their stored visibility — that is the whole point of it.
 */
function visibleVectorLayers(store, { zoom = null, isolated = null } = {}) {
    const index = buildIndex(store.state);

    return childrenInTreeOrder(store.state, index)
        .filter((placement) => isolated === null || isolated.has(placement.id))
        .filter((placement) => effectiveVisible(store.state, placement, zoom))
        .map((placement) => ({
            layer: store.state.layers[placement.layerId],
            placement,
            // Multiplied down the chain, so a group at 50% halves what is
            // under it — the renderer applies the product, not the layer's
            // own figure.
            opacity: effectiveOpacity(store.state, placement),
        }))
        .filter((entry) => entry.layer && entry.layer.kind === 'vector');
}

/**
 * Every placement, depth-first in tree order.
 *
 * Through the index, not a scan per node. Without it this is quadratic, and it
 * runs on every visibility toggle — which is the one interaction the session
 * gate puts a 50 ms budget on.
 */
function childrenInTreeOrder(state, index) {
    const out = [];
    const walk = (parentId) => {
        for (const placement of childrenOf(state, parentId, index)) {
            out.push(placement);
            walk(placement.id);
        }
    };

    walk(null);

    return out;
}

/**
 * The editor: one map open at a time, and the machinery to change which.
 */
/** How long the view must sit still before it is worth remembering. */
const VIEW_SAVE_MS = 1000;

/** Roles that may change where the map opens for everybody. */
const MAY_SET_VIEW = new Set(['owner', 'editor']);

class Editor {
    constructor(config, map) {
        this.config = config;
        this.map = map;
        this.feeds = [];
        this.bootstrap = null;
        this.store = null;
        this.sync = null;
        this.basemapLayer = null;
        this.activity = new Activity(document.getElementById('gis-activity'));

        // One counter for the tab, because `clientId` is one identity for the
        // tab. It outlives the queue, which is rebuilt on every map switch.
        this.seq = new SeqCounter();
        this.viewTimer = null;
        this.savedView = null;
        this.ignoreMovesUntil = 0;

        this.browser = new MapBrowser({
            apiBase: config.apiBase,
            strings: config.strings,
            clientId: config.clientId,
            seq: this.seq,
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

        this.tree = new LayerTree({
            container: document.getElementById('gis-sidebar'),
            strings: config.strings,
            zoom: () => this.map.getZoom(),
            zoomLimits: () => ({ min: this.map.getMinZoom(), max: this.map.getMaxZoom() }),
            onZoomTo: (layer) => this.zoomToLayer(layer),
            onRestyle: (layerId, style) => this.restyleLayer(layerId, style),
            onAddLayer: () => this.library.open(),
            onIsolate: (on) => this.setIsolate(on),
            onOpenMaps: () => this.browser.open(),
            onCollapse: (collapsed) => this.rememberPanels({ layers: !collapsed }, true),
            onRenameMap: (name) => this.renameMap(name),

            // A repaint per frame and nothing else: no command, no request,
            // no reconcile of the drawn set.
            onOpacityPreview: () => this.applyOpacities(),
            onChanged: (options) => this.treeChanged(options),
        });

        // In the sidebar, under the tree, rather than floating over the map.
        // Both are the map's furniture and both are read top-to-bottom; two
        // separate overlays on the same screen made the canvas the smaller
        // thing on it.
        this.panel = new ControlPanel({
            container: document.getElementById('gis-sidebar'),
            strings: config.strings,
            onBasemap: (id) => this.setBasemap(id),
            onGoTo: (point) => this.goTo(point),
            onCollapse: (collapsed) => this.setPanelOpen(!collapsed),
            previewTile: () => this.previewTile(),
        });

        // Client-only and not persisted. Restoring brings back the previous
        // per-layer visibility rather than turning everything on, which is why
        // it is a filter over the draw set and never a write.
        this.isolated = null;
    }

    /**
     * Push the current effective opacity of every drawn layer at the renderer.
     *
     * Cheap enough to run on every frame of a slider drag: it is a walk of the
     * tree and one number per layer, and the renderer repaints from paths it
     * has already built.
     */
    applyOpacities() {
        // One pass to index the placements by layer, not a search per feed.
        const byLayer = new Map();

        for (const placement of Object.values(this.store.state.placements)) {
            byLayer.set(placement.layerId, placement);
        }

        for (const feed of this.feeds) {
            const placement = byLayer.get(feed.layer.id);

            if (!placement) {
                continue;
            }

            feed.opacity = effectiveOpacity(this.store.state, placement);

            if (feed.slot !== null) {
                this.renderer.setOpacity(feed.slot, feed.opacity);
            }
        }

        // Groups, tiles and image overlays still fade through their pane.
        syncPanes(this.map, this.store.state);
    }

    /**
     * Tell the user their changes have stopped being saved.
     *
     * A paused queue is invisible otherwise: the UI is optimistic, so every
     * change still appears to work, and the loss only shows up on the next
     * reload. Reloading is also the recovery — the queue's contents cannot be
     * replayed against a map that has moved on — so the notice says that
     * rather than leaving the user to guess.
     */
    async reportSyncPaused(problem) {
        if (this.syncPausedReported) {
            return;
        }

        this.syncPausedReported = true;

        await notify({
            title: this.config.strings.syncPausedTitle,
            text: problem?.detail || this.config.strings.syncPaused,
            icon: 'warning',
        });
    }

    /**
     * Repaint one layer in a new colour, without refetching it.
     *
     * The features are already loaded and their paths already built; only the
     * fill and stroke differ. Going through `rebuildFeeds` instead would send
     * a multi-megabyte read over a click on a colour swatch.
     */
    restyleLayer(layerId, style) {
        for (const feed of this.feeds) {
            if (feed.layer.id === layerId && feed.slot !== null) {
                feed.entry.style = style;
                this.renderer.restyleGeometry(feed.slot, style);
            }
        }
    }

    /**
     * Something in the tree changed what is drawn.
     *
     * `reload` is for the two commands whose result the client cannot predict:
     * grouping and ungrouping both reparent rows around an id the server
     * assigns, so the tree is re-read rather than guessed at.
     */
    async treeChanged({ reload = false } = {}) {
        if (reload) {
            // Drained, not flushed: a flush returns at once when a batch is
            // already on the wire, and the read below would then race the very
            // command that made the reload necessary.
            await this.sync?.drain();
            this.bootstrap = await getJson(`${this.config.apiBase}/maps/${this.bootstrap.id}`);
            hydrate(this.store, this.bootstrap);
            this.tree.rebuild();
        }

        syncPanes(this.map, this.store.state);
        this.rebuildFeeds();
    }

    zoomToLayer(layer) {
        if (!layer?.extent) {
            return;
        }

        const [west, south, east, north] = layer.extent;

        this.ignoreMoves();
        this.map.fitBounds([[south, west], [north, east]]);
    }

    /**
     * Open or close the panels as the map remembers them.
     *
     * Both default to open: a map whose stored state predates this, or a user
     * who cannot write the view, gets the panels rather than a blank canvas
     * with two hidden controls.
     */
    applyPanels(panels = null) {
        this.setSidebarOpen(panels?.sidebar ?? true, { save: false });
        this.setPanelOpen(panels?.controls ?? true, { save: false });
        this.tree.setSectionCollapsed(!(panels?.layers ?? true));
    }

    setSidebarOpen(open, { save = true } = {}) {
        const sidebar = document.getElementById('gis-sidebar');
        const toggle = document.getElementById('gis-toggle-sidebar');

        sidebar?.classList.toggle('is-closed', !open);
        toggle?.setAttribute('aria-expanded', String(open));
        toggle?.classList.toggle('active', open);

        this.rememberPanels({ sidebar: open }, save);

        // The map's container changed width and Leaflet only finds out if it
        // is told. The move it makes is ours, not the user's, so it must not
        // decide where the map opens tomorrow.
        this.ignoreMoves();
        this.map.invalidateSize();
    }

    setPanelOpen(open, { save = true } = {}) {
        this.panel.setCollapsed(!open);
        this.rememberPanels({ controls: open }, save);
    }

    rememberPanels(patch, save) {
        this.bootstrap.viewState = {
            ...this.bootstrap.viewState,
            panels: { ...(this.bootstrap.viewState?.panels ?? {}), ...patch },
        };

        if (save) {
            this.saveView();
        }
    }

    /**
     * Rename the open map.
     *
     * Not a command, for the same reason the view write is not: `PATCH /maps`
     * carries its own version and the map's `version` is the command log's
     * replay sequence. A rename is one field on one row and has nothing to
     * merge, so it does not need the command vocabulary.
     *
     * **The slug follows the name, so the address bar has to as well** — and
     * the server owns both, since it may have had to disambiguate a duplicate.
     *
     * @returns {Promise<string|null>} the name that was actually stored
     */
    async renameMap(name) {
        try {
            const body = await patchJson(`${this.config.apiBase}/maps/${this.bootstrap.id}`, {
                name,
                version: this.bootstrap.version,
            });

            Object.assign(this.bootstrap, { name: body.name, slug: body.slug, version: body.version });
            this.store.state.map.name = body.name;
            this.store.state.map.version = body.version;
            this.rememberInUrl();
            this.browser.invalidate?.();

            return body.name;
        } catch (error) {
            await notify({
                title: this.config.strings.renameMap,
                text: error?.problem?.detail || this.config.strings.renameFailed,
            });

            return null;
        }
    }

    /**
     * Put the open map's slug in the address bar.
     *
     * `replaceState`, not `pushState`: switching maps inside the editor is not
     * a navigation the browser's Back button should step through one map at a
     * time — Back belongs to whatever brought the user here. What this buys is
     * that the URL always names what is on screen, so it can be copied, pasted
     * and bookmarked.
     */
    rememberInUrl() {
        const slug = this.bootstrap?.slug;

        if (!slug || !window.history?.replaceState) {
            return;
        }

        const url = new URL(window.location.href);

        // The editor lives at `.../gis` and `.../gis/{slug}`, so the last
        // segment is either the slug or the mount point itself.
        url.pathname = url.pathname.replace(/\/[a-z0-9-]*$/i, (last) => (
            /\/gis$/i.test(url.pathname) ? `${last}/${slug}` : `/${slug}`
        ));

        // The `?map=` form predates the slug and would now disagree with it.
        url.searchParams.delete('map');

        window.history.replaceState({}, '', url);
    }

    /**
     * The tile the basemap previews show: where the map is actually looking.
     *
     * A preview of somewhere else is a picture of a basemap rather than a
     * preview of it — the useful question is what *this* area looks like in
     * that style. Zoom 12 shows enough terrain and enough road network to tell
     * four providers apart, which a street-level tile does not.
     */
    previewTile(zoom = 12) {
        const centre = this.map.getCenter();
        const n = 2 ** zoom;
        const latitude = (centre.lat * Math.PI) / 180;

        return {
            z: zoom,
            x: Math.floor(((centre.lng + 180) / 360) * n),
            y: Math.floor(
                ((1 - Math.log(Math.tan(latitude) + 1 / Math.cos(latitude)) / Math.PI) / 2) * n,
            ),
        };
    }

    goTo(point) {
        this.map.setView([point.lat, point.lng], Math.max(this.map.getZoom(), 16));
    }

    /**
     * Solo the selected layers.
     *
     * Not persisted and never a command: the stored `visible` flags are left
     * exactly as they were, so turning it off restores what the user had
     * rather than turning everything on (specification section 8).
     */
    setIsolate(on) {
        this.isolated = on === null ? null : this.tree.selectedWithDescendants();

        if (this.isolated && this.isolated.size === 0) {
            this.isolated = null;
        }

        this.rebuildFeeds();
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
                await notify({
                    title: this.config.strings.unsyncedTitle,
                    text: this.config.strings.unsyncedChanges,
                });

                return false;
            }
        }

        this.bootstrap = bootstrap || await getJson(`${this.config.apiBase}/maps/${mapId}`);

        // The view the map arrives with is, by definition, already stored, so
        // restoring it must not write it straight back.
        const opened = this.bootstrap.viewState;

        this.savedView = opened
            ? JSON.stringify({
                center: opened.center,
                zoom: opened.zoom,
                ...(opened.basemap ? { basemap: opened.basemap } : {}),
                ...(opened.panels ? { panels: opened.panels } : {}),
            })
            : null;

        const { store, sync } = createStore(
            this.config,
            this.bootstrap.id,
            this.seq,
            (problem) => this.reportSyncPaused(problem),
        );

        this.store = store;
        this.sync = sync;

        // A map switch is the one case where nothing carries over, so the
        // renderer is emptied outright rather than reconciled.
        for (const feed of this.feeds) {
            feed.release();
        }

        this.feeds = [];
        this.renderer.clearGeometry();

        hydrate(this.store, this.bootstrap);
        this.rememberInUrl();
        this.tree.attach(this.store);
        this.panel.setProviders(
            this.bootstrap.basemaps,
            this.bootstrap.viewState?.basemap || this.bootstrap.basemaps.default,
        );
        this.applyBasemap();
        this.applyPanels(this.bootstrap.viewState?.panels);
        syncPanes(this.map, this.store.state);
        this.rebuildFeeds();

        return true;
    }

    /**
     * Switch the basemap and remember it.
     *
     * Through the unversioned view write, never a command: the map's `version`
     * is the replay sequence for its command log, and threading a hole through
     * it because somebody changed basemap would invalidate every other client
     * many times a session.
     */
    setBasemap(id) {
        this.bootstrap.viewState = { ...this.bootstrap.viewState, basemap: id };
        this.applyBasemap();
        this.saveView();
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

    /**
     * Bring the drawn set into line with the tree, keeping what has not changed.
     *
     * **Reconciled, not rebuilt.** This used to clear every layer out of the
     * renderer and construct a fresh `LayerFeed` for each visible one, so
     * hiding a single layer discarded the geometry, spatial index and built
     * paths of all the others and refetched them — megabytes and a full path
     * rebuild per layer, for one checkbox. A feed that is still wanted is now
     * kept exactly as it is, slot and held area included; a feed that is not
     * is released; only a layer that was not drawn before is fetched.
     *
     * Stack position travels as the renderer's `order` rather than as array
     * position, so a reorder is a number per layer rather than a rebuild.
     */
    rebuildFeeds() {
        const wanted = visibleVectorLayers(this.store, {
            zoom: this.map.getZoom(),
            isolated: this.isolated,
        });

        const existing = new Map(this.feeds.map((feed) => [feed.layer.id, feed]));
        const next = [];

        wanted.forEach(({ layer, opacity }, order) => {
            const feed = existing.get(layer.id);

            if (feed) {
                existing.delete(layer.id);

                // The layer object is re-read from the store on every tree
                // change, so the feed takes the new one — its style may have
                // moved on since.
                feed.layer = layer;
                feed.order = order;
                feed.opacity = opacity;

                if (feed.slot !== null) {
                    this.renderer.setOrder(feed.slot, order);
                    this.renderer.setOpacity(feed.slot, opacity);
                }

                next.push(feed);

                return;
            }

            next.push(new LayerFeed(this.renderer, this.config, layer, this.activity, order, opacity));
        });

        for (const gone of existing.values()) {
            gone.release();
        }

        this.feeds = next;
        this.refresh();
    }

    refresh() {
        this.feeds.forEach((feed) => feed.refresh(this.map));
    }

    /**
     * Remember where the map is looking, once it stops moving.
     *
     * Debounced, because a drag is a hundred `moveend`s worth of intent and one
     * worth of information, and skipped when the view has not actually changed
     * — opening a map calls `setView`, which fires `moveend` for a position the
     * server already holds.
     *
     * Not versioned and not a command: see `MapController::view()`. Losing a
     * remembered view costs the user one gesture, so this never blocks, never
     * retries and never surfaces an error.
     */
    rememberView() {
        if (this.bootstrap === null || !MAY_SET_VIEW.has(this.store?.state.map?.role)) {
            return;
        }

        // A move the user did not make is not a view worth remembering. The
        // only one this code causes is `invalidateSize()` after the container
        // changes size, which pans to keep the centre anchored and fires
        // `moveend` for it — and a window resize or a dock opening must not
        // decide where this map opens tomorrow.
        if (performance.now() < this.ignoreMovesUntil) {
            return;
        }

        if (this.viewTimer !== null) {
            clearTimeout(this.viewTimer);
        }

        this.viewTimer = setTimeout(() => {
            this.viewTimer = null;
            this.saveView();
        }, VIEW_SAVE_MS);
    }

    /** Ignore the moves the next container resize is about to cause. */
    ignoreMoves(windowMs = 600) {
        this.ignoreMovesUntil = performance.now() + windowMs;
    }

    saveView() {
        const centre = this.map.getCenter();
        const view = {
            center: [Number(centre.lng.toFixed(6)), Number(centre.lat.toFixed(6))],
            zoom: this.map.getZoom(),
        };

        const basemap = this.bootstrap.viewState?.basemap;

        if (basemap) {
            view.basemap = basemap;
        }

        // ALWAYS sent, both keys, because the server MERGES what it is given so
        // that a client which knows only where it is looking cannot erase the
        // basemap. An absent key therefore reads as "unchanged" — so a state
        // the user chose has to be sent explicitly, even when it is the empty
        // or default one. Omitting it is how closing the last panel used to
        // fail to save.
        view.panels = this.bootstrap.viewState?.panels ?? {};

        const signature = JSON.stringify(view);

        if (signature === this.savedView) {
            return;
        }

        this.bootstrap.viewState = { ...this.bootstrap.viewState, ...view };

        // The signature is recorded only once the write has landed. Setting it
        // first means a write that never happened — refused, offline, raced —
        // is remembered as saved, and the next identical state is then skipped
        // as a duplicate. The failure is silent and the state is simply lost.
        putJson(`${this.config.apiBase}/maps/${this.bootstrap.id}/view`, view)
            .then(() => {
                this.savedView = signature;
            })
            .catch((error) => console.warn('gis: could not remember the view', error));
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
                seq: this.seq.next(),
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

/**
 * Ask before leaving the editor.
 *
 * The editor is a working surface, not a page: a stray click on the way out
 * costs whatever is on screen, and the browser's own "leave site?" prompt only
 * fires for a form, which this is not.
 *
 * **The question changes when there is work in flight.** A queue that has not
 * drained means changes that exist on screen and nowhere else, and saying so
 * is the difference between a confirmation worth reading and one everybody
 * learns to click through.
 */
function confirmLeaving(link, config, editor) {
    if (!link) {
        return;
    }

    link.addEventListener('click', async (event) => {
        // Captured now: `currentTarget` is null by the time the await resolves.
        const { href } = event.currentTarget;
        const queue = editor.sync?.describe();
        const unsaved = (queue?.pending.length ?? 0) + (queue?.inFlight.length ?? 0) > 0;

        event.preventDefault();

        const confirmed = await confirmAction({
            title: config.strings.backToDashboard,
            text: unsaved ? config.strings.leaveUnsaved : config.strings.confirmLeave,
            confirmLabel: config.strings.leave,
            cancelLabel: config.strings.cancel,
            dangerous: unsaved,
        });

        if (confirmed) {
            window.location.href = href;
        }
    });
}

/** Decimal places kept in the readout: 1e-5 degrees is about a metre. */
const READOUT_PLACES = 5;

/**
 * Show where the pointer is, at the bottom right of the map.
 *
 * **Latitude first**, which is the opposite of the order everything else in
 * this package uses. On the wire and in the database a coordinate is
 * longitude-latitude, because that is what GeoJSON and WKB define; but a
 * person reading a pair off a screen, or pasting one into a search box, writes
 * latitude first. The element carries a translated `title` saying which is
 * which, because a reader who guesses wrong lands in the wrong hemisphere and
 * nothing tells them.
 *
 * Updated on a frame rather than on every `mousemove`: the browser fires those
 * faster than it paints, and each one is a text write that invalidates layout.
 */
function trackPointer(map, readout) {
    if (!readout) {
        return;
    }

    let pending = null;
    let frame = null;

    map.on('mousemove', (event) => {
        pending = event.latlng;

        if (frame !== null) {
            return;
        }

        frame = window.requestAnimationFrame(() => {
            frame = null;
            readout.textContent = `${pending.lat.toFixed(READOUT_PLACES)}, ${pending.lng.toFixed(READOUT_PLACES)}`;
            readout.hidden = false;
        });
    });

    // Leaving the map leaves the last position on screen, which reads as the
    // pointer still being there. On a touch screen there is no hover at all,
    // so the readout simply never appears — which is right: there is nothing
    // to report.
    map.on('mouseout', () => {
        window.cancelAnimationFrame(frame);
        frame = null;
        readout.hidden = true;
    });
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

    trackPointer(map, document.getElementById('gis-coordinates'));

    map.on('moveend zoomend', () => {
        editor.refresh();
        editor.rememberView();
    });

    // Docks and toolbars resize the map container without the window changing,
    // so the map has to be told (specification section 17).
    let resizeTimer = null;
    new ResizeObserver(() => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(() => {
            editor.ignoreMoves();
            map.invalidateSize();
        }, 100);
    }).observe(container);

    confirmLeaving(document.querySelector('.gis-back'), config, editor);

    // Through the editor, which owns the state and remembers it with the map.
    document.getElementById('gis-toggle-sidebar')?.addEventListener('click', () => {
        editor.setSidebarOpen(document.getElementById('gis-sidebar').classList.contains('is-closed'));
    });

    // Delegated, so the tree's recycled rows are covered without rebinding on
    // every render.
    enableTooltips(document.getElementById('gis-app'));

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
