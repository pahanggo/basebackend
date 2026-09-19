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

        // The same shape as the layer tree's section toggle above it: a caret
        // and a label. They do the same thing, so they are the same control.
        this.caret = el('i', { class: 'la la-caret-down', 'aria-hidden': 'true' });

        this.toggle = el('button', {
            type: 'button',
            class: 'gis-section-toggle gis-panel-toggle',
            'aria-expanded': 'true',
            title: strings.mapControls,
            'aria-controls': 'gis-panel-body',
            onclick: () => {
                this.setCollapsed(!this.collapsed);
                this.onCollapse?.(this.collapsed);
            },
        }, [this.caret, el('span', { text: strings.mapControls })]);

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
        this.caret.className = `la la-caret-${collapsed ? 'right' : 'down'}`;
        this.root.classList.toggle('is-collapsed', collapsed);
    }
}

/**
 * Longitude and latitude from the formats section 11 names.
 *
 * Delegates to `lib/coordinates.js`, which S10 built: decimal degrees,
 * degrees-minutes-seconds, UTM and MGRS. This used to carry its own parser for
 * the first two and a note that the other two "arrive with the measurement
 * work" — they have, and two parsers for one box is one too many.
 *
 * Kept under this name because it is what this module has always exported.
 */
export { parse as parseCoordinate } from '../lib/coordinates.js';
