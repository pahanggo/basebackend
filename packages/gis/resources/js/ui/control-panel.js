/**
 * The map control panel: where you are looking, and what you are looking for.
 *
 * The split from the layer tree is deliberate and worth keeping. The tree owns
 * *what exists and in what order*; this panel owns *where you are looking*.
 * Isolate started here and moved to the tree — it acts on the tree's
 * selection, and a control that solos "the selection" belongs where the
 * selection is made (specification section 8).
 *
 * It is chrome, so it sits above the map canvas — and an active draw or edit
 * tool collapses it, so it can never intercept a drawing gesture.
 */

import { el, clear } from '../lib/dom.js';

/**
 * Basemap ids are slugs from the tile service, not labels. Turning
 * `mapbox-satellite-streets` into `Mapbox satellite streets` here keeps the
 * service free to add a provider without a translation entry — a new one reads
 * acceptably rather than appearing as a raw slug or not at all.
 */
export function providerLabel(id) {
    return id
        .replace(/^owm-/, '')
        .split('-')
        .map((word, index) => (index === 0 ? word.charAt(0).toUpperCase() + word.slice(1) : word))
        .join(' ');
}

export class ControlPanel {
    /**
     * @param {Object} options
     * @param {HTMLElement} options.container
     * @param {Object} options.strings
     * @param {Function} options.onBasemap  (id) => void
     * @param {Function} options.onGoTo     ({lng, lat}) => void
     */
    constructor({ container, strings, onBasemap, onGoTo, onCollapse = null, previewTile = null }) {
        this.strings = strings;
        this.previewTile = previewTile;
        this.onCollapse = onCollapse;
        this.onBasemap = onBasemap;
        this.onGoTo = onGoTo;

        this.basemap = null;

        // The basemaps on offer, as previewed tiles. The set is
        // `gis.basemaps.featured` — see `setProviders`.
        this.basemapGrid = el('div', { class: 'gis-panel-grid', role: 'radiogroup' });

        this.coordinate = el('input', {
            type: 'text',
            class: 'form-control form-control-sm',
            placeholder: strings.goToPlaceholder,
            'aria-label': strings.goTo,
        });

        this.coordinateError = el('div', { class: 'gis-panel-error', role: 'alert', hidden: true });

        this.bodyNode = el('div', { class: 'gis-panel-body' }, [
            this.section(strings.basemap, this.basemapGrid),
            this.section(strings.goTo, el('div', {}, [this.coordinate, this.coordinateError])),
        ]);

        this.toggle = el('button', {
            type: 'button',
            class: 'btn btn-sm btn-light gis-panel-toggle',
            'aria-expanded': 'true',
            title: strings.mapControls,
            'aria-controls': 'gis-panel-body',
            onclick: () => {
                this.setCollapsed(!this.collapsed);
                this.onCollapse?.(this.collapsed);
            },
        }, [el('i', { class: 'la la-layer-group', 'aria-hidden': 'true' }), ` ${strings.mapControls}`]);

        this.bodyNode.id = 'gis-panel-body';
        this.collapsed = false;

        this.root = el('div', { class: 'gis-panel', role: 'region', 'aria-label': strings.mapControls }, [
            this.toggle,
            this.bodyNode,
        ]);

        container.append(this.root);

        this.coordinate.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                this.goTo();
            }
        });

        // Paste detection: a pasted coordinate is almost always meant to be
        // used immediately, and asking for a second keystroke to confirm it is
        // a step nobody wants.
        this.coordinate.addEventListener('paste', () => window.setTimeout(() => this.goTo(), 0));
    }

    section(title, ...children) {
        return el('section', { class: 'gis-panel-section' }, [
            el('h6', { class: 'gis-panel-heading', text: title }),
            ...children,
        ]);
    }

    /**
     * Fill the picker from the bootstrap's provider list.
     *
     * The list is fetched server-side and inlined, never fetched from here: a
     * second origin on the critical path for a list that changes a few times a
     * year is a poor trade, and the fallbacks belong where the cache is
     * (specification section 13).
     */
    setProviders({ featured = [], urlTemplate = '' }, active) {
        this.basemap = active;
        this.urlTemplate = urlTemplate;

        clear(this.basemapGrid);

        for (const entry of featured) {
            this.basemapGrid.append(this.basemapTile(entry.id, entry.label));
        }
    }

    /**
     * One previewed basemap: a real tile of where the map is looking.
     *
     * The tile is the same URL the map itself would request, so the preview is
     * the provider rather than a picture of it — a stylesheet change upstream
     * shows here without anything being regenerated, and there is nothing to
     * keep in step.
     *
     * The label stays under the image rather than being replaced by it. A grid
     * of four unlabelled thumbnails is a guessing game for anyone who cannot
     * tell two dark basemaps apart at 96 pixels, and for a screen reader it is
     * nothing at all.
     */
    basemapTile(id, label = null) {
        const input = el('input', {
            type: 'radio',
            name: 'gis-basemap',
            class: 'gis-panel-radio sr-only',
            id: `gis-basemap-${id}`,
            value: id,
            checked: id === this.basemap,
        });

        input.addEventListener('change', () => {
            this.basemap = id;
            this.markSelected();
            this.onBasemap(id);
        });

        const tile = this.previewTile?.();
        const preview = tile && this.urlTemplate
            ? el('img', {
                class: 'gis-panel-preview',
                src: this.urlTemplate
                    .replace(/\/tiles\/[^/]+\//, `/tiles/${id}/`)
                    .replace('{x}', tile.x)
                    .replace('{y}', tile.y)
                    .replace('{z}', tile.z),
                alt: '',
                loading: 'lazy',
                width: '96',
                height: '96',
            })
            : el('span', { class: 'gis-panel-preview is-missing', 'aria-hidden': 'true' });

        return el('label', {
            class: `gis-panel-tile${id === this.basemap ? ' is-selected' : ''}`,
            for: `gis-basemap-${id}`,
            // The full provider name, for the one whose label is ellipsised.
            title: providerLabel(id),
            dataset: { basemap: id },
        }, [
            input,
            preview,
            // The label is ours and translated; the id is the service's and is
            // only a fallback for a provider nobody has named yet.
            el('span', { class: 'gis-panel-tile-label', text: label || providerLabel(id) }),
        ]);
    }

    /** The selected outline, which is on the label rather than the hidden radio. */
    markSelected() {
        for (const tile of this.basemapGrid.querySelectorAll('.gis-panel-tile')) {
            tile.classList.toggle('is-selected', tile.dataset.basemap === this.basemap);
        }
    }

    goTo() {
        const point = parseCoordinate(this.coordinate.value);

        this.coordinateError.hidden = point !== null;

        if (point === null) {
            this.coordinateError.textContent = this.strings.coordinateInvalid;

            return;
        }

        this.onGoTo(point);
    }

    setCollapsed(collapsed) {
        this.collapsed = collapsed;
        this.bodyNode.hidden = collapsed;
        this.toggle.setAttribute('aria-expanded', String(!collapsed));
        this.root.classList.toggle('is-collapsed', collapsed);
    }
}

/**
 * Longitude and latitude from the formats section 11 names.
 *
 * Decimal degrees, degrees-minutes-seconds, and either order — a pasted
 * coordinate is far more often `lat, lng` than `lng, lat`, so a pair inside
 * ±90 is read latitude-first and anything with a longitude beyond that is
 * unambiguous. Returns null rather than guessing when it cannot tell.
 *
 * UTM and MGRS are section 11's job and arrive with the measurement work; this
 * accepts what can be parsed without a projection library.
 */
export function parseCoordinate(text) {
    const value = String(text || '').trim();

    if (value === '') {
        return null;
    }

    const dms = value.match(
        /^\s*(\d+(?:\.\d+)?)[°\s]+(\d+(?:\.\d+)?)['′\s]+(\d+(?:\.\d+)?)["″\s]*([NSns])[,\s]+(\d+(?:\.\d+)?)[°\s]+(\d+(?:\.\d+)?)['′\s]+(\d+(?:\.\d+)?)["″\s]*([EWew])\s*$/
    );

    if (dms) {
        const lat = toDecimal(dms[1], dms[2], dms[3], dms[4]);
        const lng = toDecimal(dms[5], dms[6], dms[7], dms[8]);

        return inRange(lng, lat) ? { lng, lat } : null;
    }

    const pair = value.match(/^\s*(-?\d+(?:\.\d+)?)\s*[,\s]\s*(-?\d+(?:\.\d+)?)\s*$/);

    if (!pair) {
        return null;
    }

    const a = Number(pair[1]);
    const b = Number(pair[2]);

    // A value beyond ±90 can only be a longitude, which settles the order
    // without guessing. Otherwise assume latitude first, as every mapping
    // service and every pasted URL does.
    if (Math.abs(a) > 90 && Math.abs(b) <= 90) {
        return inRange(a, b) ? { lng: a, lat: b } : null;
    }

    return inRange(b, a) ? { lng: b, lat: a } : null;
}

function toDecimal(degrees, minutes, seconds, hemisphere) {
    const value = Number(degrees) + Number(minutes) / 60 + Number(seconds) / 3600;

    return /[SsWw]/.test(hemisphere) ? -value : value;
}

function inRange(lng, lat) {
    return Number.isFinite(lng) && Number.isFinite(lat)
        && Math.abs(lng) <= 180 && Math.abs(lat) <= 90;
}
