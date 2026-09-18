/**
 * One Leaflet pane per top-level tree group, z-ordered by tree position.
 *
 * This is the only mechanism that gives real z-ordering between the custom
 * canvas layer, tile layers and Leaflet's own editing handles: within a pane,
 * order is DOM order; between panes, it is `zIndex`.
 */

const BASE_Z_INDEX = 400;

/**
 * @param {L.Map} map
 * @param {string} name
 * @param {number} order position in the tree, 0 at the bottom
 */
export function ensurePane(map, name, order = 0) {
    const id = `gis-${name}`;
    const pane = map.getPane(id) || map.createPane(id);

    pane.style.zIndex = String(BASE_Z_INDEX + order * 10);

    return id;
}
