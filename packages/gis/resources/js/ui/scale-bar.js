/**
 * How far across the map a given length of screen is.
 *
 * **Two bars, SI and imperial, because they answer different readers** and the
 * cost of the second is a few pixels (specification §11). Leaflet ships a
 * scale control and this is not it: Leaflet's measures along the top edge of
 * the container, and this measures at the map's CENTRE latitude — which is
 * where the user is looking, and which differs from the edge by a noticeable
 * amount on a tall viewport away from the equator.
 *
 * The bar length is rounded to a figure worth reading: 1, 2 or 5 times a power
 * of ten. A bar labelled "137 m" is arithmetic the reader has to do; one
 * labelled "100 m" is a ruler.
 */

import { el } from '../lib/dom.js';
import { distance } from '../lib/measure.js';

/** The widest the bar may be, in screen pixels. */
const MAX_WIDTH = 110;

export class ScaleBar {
    /**
     * @param {Object} options
     * @param {HTMLElement} options.container the map shell
     * @param {Object} options.map the Leaflet map
     */
    constructor({ container, map }) {
        this.map = map;

        this.metric = el('div', { class: 'gis-scale-line is-metric' });
        this.imperial = el('div', { class: 'gis-scale-line is-imperial' });

        this.element = el('div', {
            class: 'gis-scale',
            'aria-hidden': 'true',
        }, [this.metric, this.imperial]);

        container.append(this.element);

        // `moveend` as well as `move`, because a zoom is animated: during the
        // animation `containerPointToLatLng` still answers for the projection
        // the map is leaving, so a bar updated only on `move` reads one zoom
        // level behind until the next interaction.
        map.on('move moveend zoom zoomend resize', () => this.update());
        this.update();
    }

    /**
     * Metres per pixel at the map's centre, measured rather than derived.
     *
     * Asking the map to unproject two points a hundred pixels apart and
     * measuring between them geodesically gives the same answer as the
     * standard formula and cannot drift from whatever projection Leaflet is
     * actually using.
     */
    metresPerPixel() {
        const size = this.map.getSize();
        const y = size.y / 2;
        const left = this.map.containerPointToLatLng([size.x / 2 - 50, y]);
        const right = this.map.containerPointToLatLng([size.x / 2 + 50, y]);

        return distance([left.lng, left.lat], [right.lng, right.lat]) / 100;
    }

    update() {
        if (!this.map._loaded) {
            return;
        }

        const perPixel = this.metresPerPixel();

        if (!Number.isFinite(perPixel) || perPixel <= 0) {
            return;
        }

        this.draw(this.metric, perPixel * MAX_WIDTH, [
            { limit: 1000, per: 1, unit: 'm' },
            { limit: Infinity, per: 1000, unit: 'km' },
        ], perPixel);

        this.draw(this.imperial, perPixel * MAX_WIDTH, [
            { limit: 1609.344, per: 0.3048, unit: 'ft' },
            { limit: Infinity, per: 1609.344, unit: 'mi' },
        ], perPixel);
    }

    /**
     * One bar: pick the unit, round the length, size the element.
     *
     * @param {Array<{limit: number, per: number, unit: string}>} ladder
     */
    draw(element, maxMetres, ladder, perPixel) {
        const step = ladder.find((entry) => maxMetres < entry.limit) ?? ladder[ladder.length - 1];
        const rounded = roundToReadable(maxMetres / step.per);

        element.style.width = `${Math.round((rounded * step.per) / perPixel)}px`;
        element.textContent = `${trim(rounded)} ${step.unit}`;
    }

    destroy() {
        this.element.remove();
    }
}

/**
 * The largest of 1, 2 or 5 times a power of ten that fits.
 *
 * A bar labelled "137 m" is arithmetic the reader has to do; one labelled
 * "100 m" is a ruler.
 */
export function roundToReadable(value) {
    if (!(value > 0)) {
        return 0;
    }

    const magnitude = 10 ** Math.floor(Math.log10(value));

    for (const multiple of [5, 2, 1]) {
        if (value >= multiple * magnitude) {
            return multiple * magnitude;
        }
    }

    return magnitude;
}

function trim(value) {
    return String(Number(value.toFixed(3)));
}
