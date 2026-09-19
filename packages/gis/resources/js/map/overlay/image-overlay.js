/**
 * A georeferenced image, warped onto four corners by the GPU.
 *
 * The image is a real `<img>` in its own Leaflet pane, carrying a `matrix3d`
 * transform, rather than something drawn onto a canvas. Three things follow
 * from that and all three are the reason for it:
 *
 * - **A corner drag costs no drawing at all.** The browser recomposites a
 *   layer it already has; the feature canvas is never touched, so dragging a
 *   corner over 40,000 parcels is as cheap as dragging it over an empty map.
 * - **It composites like every other layer**, through the same pane z-index
 *   the tree already assigns, so an overlay can sit between two vector groups.
 * - **`matrix3d` can express perspective.** A 2D CSS `matrix` cannot, and
 *   would drop the terms silently rather than refusing them.
 *
 * The corners are stored as longitude and latitude, so the transform is
 * recomputed whenever the map moves — the image's own pixels never change, only
 * where the four points land on screen.
 */

import { homography, toMatrix3d } from './homography.js';

/** Corner order, and the order they are stored in `source_config`. */
export const CORNERS = ['nw', 'ne', 'se', 'sw'];

export class ImageOverlay {
    /**
     * @param {Object} map the Leaflet map
     * @param {string} paneId the pane this overlay draws into
     * @param {Object} source `source_config`: url, naturalWidth/Height, corners
     */
    constructor(map, paneId, source) {
        this.map = map;
        this.source = source;
        this.element = document.createElement('img');

        this.element.className = 'gis-overlay-image';
        this.element.draggable = false;
        this.element.alt = '';
        this.element.src = source.url;

        // The element is sized in the image's OWN pixels and then warped. Sizing
        // it in screen pixels instead would mean re-deriving the homography
        // against a moving target every frame, and would resample twice.
        this.element.style.width = `${source.naturalWidth}px`;
        this.element.style.height = `${source.naturalHeight}px`;
        this.element.style.transformOrigin = '0 0';
        this.element.style.position = 'absolute';

        const pane = map.getPane(paneId);

        if (pane) {
            pane.appendChild(this.element);
        }
    }

    /** Where each corner currently sits, in container pixels. */
    containerCorners() {
        return CORNERS.map((name) => {
            const [lng, lat] = this.source.corners[name];
            const point = this.map.latLngToLayerPoint([lat, lng]);

            return [point.x, point.y];
        });
    }

    /**
     * Recompute the warp for where the map is now.
     *
     * Called on every move and zoom. The solve is a few hundred floating-point
     * operations and the paint is the GPU's, so this is affordable per frame —
     * which is what lets a corner drag update live rather than on release.
     */
    update() {
        const { naturalWidth: w, naturalHeight: h } = this.source;
        const matrix = homography([[0, 0], [w, 0], [w, h], [0, h]], this.containerCorners());

        if (matrix === null) {
            // A quad collapsed to a line or a point. Hiding it is honest: there
            // is no image to show, and an unchecked solve would paint infinities.
            this.element.style.visibility = 'hidden';

            return;
        }

        this.element.style.visibility = '';
        this.element.style.transform = toMatrix3d(matrix);
    }

    /** Take a new `source_config` — a corner moved, or the opacity changed. */
    setSource(source) {
        if (source.url !== this.source.url) {
            this.element.src = source.url;
            this.element.style.width = `${source.naturalWidth}px`;
            this.element.style.height = `${source.naturalHeight}px`;
        }

        this.source = source;
        this.update();
    }

    setOpacity(opacity) {
        this.element.style.opacity = String(opacity);
    }

    remove() {
        this.element.remove();
    }
}

/**
 * Where a newly uploaded image should land.
 *
 * **A quarter of the viewport's AREA, not its width.** Quarter-width leaves a
 * tall image running off the top and bottom of the screen, which is the first
 * thing a user has to fix and the least interesting (specification section 13).
 * Scaling by area makes a wide image land wide and short and a tall one tall
 * and narrow, and neither escapes the viewport.
 *
 * @returns {{nw: number[], ne: number[], se: number[], sw: number[]}} lon-lat pairs
 */
export function initialCorners(map, naturalWidth, naturalHeight) {
    const size = map.getSize();
    const scale = Math.sqrt((0.25 * size.x * size.y) / (naturalWidth * naturalHeight));
    const halfWidth = (naturalWidth * scale) / 2;
    const halfHeight = (naturalHeight * scale) / 2;
    const centre = { x: size.x / 2, y: size.y / 2 };

    const at = (dx, dy) => {
        const latLng = map.containerPointToLatLng([centre.x + dx, centre.y + dy]);

        return [latLng.lng, latLng.lat];
    };

    // Axis-aligned to begin with, which it stops being the moment a corner
    // moves. That is the correct starting state: obviously placed, obviously
    // not yet aligned.
    return {
        nw: at(-halfWidth, -halfHeight),
        ne: at(halfWidth, -halfHeight),
        se: at(halfWidth, halfHeight),
        sw: at(-halfWidth, halfHeight),
    };
}
