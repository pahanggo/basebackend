/**
 * Leaflet panes, one per top-level tree node, z-ordered by tree position.
 *
 * This is the only mechanism that gives real z-ordering between the custom
 * canvas layer, tile layers, image overlays and Leaflet's own editing handles:
 * within a pane, order is DOM order; between panes, it is `zIndex`
 * (specification section 8).
 *
 * Z-index runs in steps of 100 from the tree, so a raster overlay can be
 * dropped between two vector groups without renumbering anything, and an image
 * overlay — a DOM `<img>` carrying a `matrix3d` transform, not canvas content —
 * gets a pane of its own rather than a place in a draw list it cannot join.
 *
 * **Tree order is bottom-up on screen, top-down in the list.** The first row in
 * the tree is the topmost layer, which is what every GIS tool does and the
 * opposite of what a naive `order * 100` gives you.
 */

import { childrenOf, effectiveOpacity } from '../ui/tree-model.js';

const BASE_Z_INDEX = 400;
const STEP = 100;

/**
 * @param {L.Map} map
 * @param {string} name
 * @param {number} order position from the bottom of the stack
 */
export function ensurePane(map, name, order = 0) {
    const id = `gis-${name}`;
    const pane = map.getPane(id) || map.createPane(id);

    pane.style.zIndex = String(BASE_Z_INDEX + order * 10);

    return id;
}

/**
 * Give every top-level node a pane, z-ordered and faded to match the tree.
 *
 * Called after any change to order, visibility or opacity. Panes for nodes
 * that have gone are emptied rather than removed — Leaflet holds references to
 * a pane it was given, and removing one out from under a layer leaves that
 * layer painting into a detached element.
 *
 * @returns {Map<number, string>} placement id -> pane id
 */
export function syncPanes(map, state) {
    const top = childrenOf(state, null);
    const panes = new Map();

    top.forEach((placement, index) => {
        const id = `gis-node-${placement.id}`;
        const pane = map.getPane(id) || map.createPane(id);

        // Reversed: the first row in the tree paints on top.
        pane.style.zIndex = String(BASE_Z_INDEX + (top.length - index) * STEP);

        // Opacity multiplies down the chain, and a pane is where a group's
        // share of it can actually be applied — the feature canvas is shared
        // between vector layers until per-layer styling lands (S8), so a group
        // fades here and a single vector layer inside one does not yet.
        pane.style.opacity = String(effectiveOpacity(state, placement));
        pane.style.display = placement.visible ? '' : 'none';

        panes.set(placement.id, id);
    });

    return panes;
}
