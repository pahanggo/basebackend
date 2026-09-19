/**
 * The map control panel: where you are looking, and what you are looking for.
 *
 * The split from the layer tree is deliberate and worth keeping. The tree owns
 * *what exists and in what order*; this panel owns *the view*. Basemap, weather
 * overlays, go-to-coordinate, search and isolate all change what is on screen
 * without changing what the map contains (specification section 8).
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
     * @param {Function} options.onOverlays (ids) => void
     * @param {Function} options.onGoTo     ({lng, lat}) => void
     * @param {Function} options.onIsolate  (placementId|null) => void
     */
    constructor({ container, strings, onBasemap, onOverlays, onGoTo, onIsolate, onCollapse = null, previewTile = null }) {
        this.strings = strings;
        this.previewTile = previewTile;
        this.onCollapse = onCollapse;
        this.onBasemap = onBasemap;
        this.onOverlays = onOverlays;
        this.onGoTo = onGoTo;
        this.onIsolate = onIsolate;

        this.basemap = null;
        this.overlays = new Set();
        this.isolated = null;

        // Four previews in a grid, then the rest as a plain list. Twenty radio
        // buttons is a list to read; four pictures is a choice to make.
        this.basemapGrid = el('div', { class: 'gis-panel-grid', role: 'radiogroup' });
        this.basemapList = el('div', { class: 'gis-panel-basemaps', role: 'radiogroup' });
        this.moreToggle = el('button', {
            type: 'button',
            class: 'btn btn-link btn-sm gis-panel-more',
            'aria-expanded': 'false',
            onclick: () => this.setMoreOpen(this.basemapList.hidden),
        }, [strings.moreBasemaps]);
        this.overlayList = el('div', { class: 'gis-panel-overlays', role: 'group' });

        this.coordinate = el('input', {
            type: 'text',
            class: 'form-control form-control-sm',
            placeholder: strings.goToPlaceholder,
            'aria-label': strings.goTo,
        });

        this.coordinateError = el('div', { class: 'gis-panel-error', role: 'alert', hidden: true });

        this.isolateButton = el('button', {
            type: 'button',
            class: 'btn btn-sm btn-light btn-block gis-panel-isolate',
            'aria-pressed': 'false',
            title: strings.isolateHint,
            onclick: () => this.toggleIsolate(),
        }, [el('i', { class: 'la la-eye', 'aria-hidden': 'true' }), ` ${strings.isolate}`]);

        this.bodyNode = el('div', { class: 'gis-panel-body' }, [
            this.section(strings.basemap, this.basemapGrid, this.moreToggle, this.basemapList),
            this.overlaySection = this.section(strings.overlays, this.overlayList),
            this.section(strings.goTo, el('div', {}, [this.coordinate, this.coordinateError])),
            this.section(strings.view, this.isolateButton),
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
        this.basemapList.hidden = true;

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
    setProviders({ basemaps = [], overlays = [], featured = [], urlTemplate = '' }, active, activeOverlays = []) {
        this.basemap = active;
        this.overlays = new Set(activeOverlays);
        this.urlTemplate = urlTemplate;

        clear(this.basemapGrid);
        clear(this.basemapList);

        for (const entry of featured) {
            this.basemapGrid.append(this.basemapTile(entry.id, entry.label));
        }

        const featuredIds = featured.map((entry) => entry.id);
        const rest = basemaps.filter((id) => !featuredIds.includes(id));

        for (const id of rest) {
            this.basemapList.append(this.basemapOption(id));
        }

        // Nothing left over is not a disclosure button with an empty list
        // behind it.
        this.moreToggle.hidden = rest.length === 0;

        // **Always closed on load.** The four previews are the point of the
        // panel; opening a twenty-item list every time because the active
        // basemap happens to live in it would bury them. Where the active one
        // IS in that list, the toggle names it — so the selection is visible
        // without the list being open, which is what the disclosure was
        // hiding in the first place.
        this.setMoreOpen(false);
        this.labelMore(rest.includes(active) ? active : null);

        clear(this.overlayList);

        for (const id of overlays) {
            this.overlayList.append(this.overlayOption(id));
        }

        // Nothing to toggle is not an empty box with a heading over it.
        this.overlaySection.hidden = overlays.length === 0;
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
            this.labelMore(null);
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

    setMoreOpen(open) {
        this.basemapList.hidden = !open;
        this.moreToggle.setAttribute('aria-expanded', String(open));
    }

    /** Name the active basemap on the toggle when it is one of the hidden ones. */
    labelMore(activeId) {
        clear(this.moreToggle);

        this.moreToggle.append(activeId
            ? `${this.strings.moreBasemaps} — ${providerLabel(activeId)}`
            : this.strings.moreBasemaps);
    }

    basemapOption(id) {
        const input = el('input', {
            type: 'radio',
            name: 'gis-basemap',
            class: 'gis-panel-radio',
            id: `gis-basemap-${id}`,
            value: id,
            checked: id === this.basemap,
        });

        input.addEventListener('change', () => {
            this.basemap = id;
            this.markSelected();
            this.labelMore(id);
            this.onBasemap(id);
        });

        return el('label', { class: 'gis-panel-option', for: `gis-basemap-${id}` }, [
            input,
            el('span', { text: providerLabel(id) }),
        ]);
    }

    /**
     * A weather overlay, independently toggled.
     *
     * Classified by the `owm-` prefix on the server, so a sixth one appears
     * here without a code change — which is the reason the prefix rule exists
     * rather than a hardcoded list.
     */
    overlayOption(id) {
        const input = el('input', {
            type: 'checkbox',
            class: 'gis-panel-checkbox',
            id: `gis-overlay-${id}`,
            checked: this.overlays.has(id),
        });

        input.addEventListener('change', () => {
            if (input.checked) {
                this.overlays.add(id);
            } else {
                this.overlays.delete(id);
            }

            this.onOverlays([...this.overlays]);
        });

        return el('label', { class: 'gis-panel-option', for: `gis-overlay-${id}` }, [
            input,
            el('span', { text: providerLabel(id) }),
        ]);
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

    /**
     * Solo the selected layer.
     *
     * Client-only and not persisted, and restoring brings back the previous
     * per-layer visibility rather than turning everything on — which is why the
     * editor, not this panel, owns the remembered state.
     */
    toggleIsolate() {
        this.isolated = this.isolated === null ? true : null;
        this.isolateButton.setAttribute('aria-pressed', String(this.isolated !== null));
        this.isolateButton.classList.toggle('active', this.isolated !== null);
        this.onIsolate(this.isolated);
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
