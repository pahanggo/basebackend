/**
 * GIS editor entry point.
 *
 * S0 boots the page and nothing more: read the bootstrap blob, put a basemap on
 * the screen, and prove the build pipeline reaches the browser. The renderer
 * (S2), the store (S4) and the layer tree (S5) mount from here later.
 *
 * Leaflet is the global `L`, loaded from the vendored UMD build before this
 * bundle. It is never imported.
 */

/** @returns {Object} the configuration blob rendered into the page */
function readBootstrap() {
    const el = document.getElementById('gis-bootstrap');

    if (!el) {
        throw new Error('gis: bootstrap blob missing');
    }

    return JSON.parse(el.textContent);
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

    // Docks and toolbars resize the map container without the window changing,
    // so the map has to be told (specification section 17).
    let resizeTimer = null;
    new ResizeObserver(() => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(() => map.invalidateSize(), 100);
    }).observe(container);

    window.gis = { map, config };
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
