/**
 * The two pure parts of the map control panel.
 *
 * Coordinate parsing is where a silent wrong answer is easiest: reading a
 * pasted `lat, lng` as `lng, lat` recentres the map on the wrong continent
 * without erroring, which is the same axis-order footgun the server side has,
 * in a different coat.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { parseCoordinate, providerLabel } from '../../resources/js/ui/control-panel.js';

test('a pasted pair is read latitude first, as every mapping service writes it', () => {
    assert.deepEqual(parseCoordinate('3.8077, 103.326'), { lng: 103.326, lat: 3.8077 });
    assert.deepEqual(parseCoordinate('3.8077 103.326'), { lng: 103.326, lat: 3.8077 });
});

test('a value beyond 90 can only be a longitude, which settles the order without guessing', () => {
    assert.deepEqual(parseCoordinate('103.326, 3.8077'), { lng: 103.326, lat: 3.8077 });
});

test('degrees, minutes and seconds with hemispheres', () => {
    const point = parseCoordinate('3° 48\' 27.7" N, 103° 19\' 33.6" E');

    assert.ok(Math.abs(point.lat - 3.80769) < 1e-4, `${point.lat}`);
    assert.ok(Math.abs(point.lng - 103.32600) < 1e-4, `${point.lng}`);

    const south = parseCoordinate('3° 48\' 27.7" S, 103° 19\' 33.6" W');

    assert.ok(south.lat < 0 && south.lng < 0);
});

test('it returns null rather than guessing at something it cannot read', () => {
    for (const input of ['', '  ', 'Kuantan', '200, 200', '3.8077', 'NaN, NaN', '95, 95']) {
        assert.equal(parseCoordinate(input), null, `${JSON.stringify(input)} should not parse`);
    }
});

test('a provider slug reads acceptably without a translation entry', () => {
    // The featured four carry names from config and are translated; this is
    // the fallback for everything else the service offers.
    // The tile service is free to add a provider; a new one must not appear as
    // a raw slug, and must not fail to appear at all.
    assert.equal(providerLabel('mapbox-satellite-streets'), 'Mapbox satellite streets');
    assert.equal(providerLabel('grayscale'), 'Grayscale');

    // The `owm-` prefix classifies an overlay on the server; it is not part of
    // the name the user reads.
    assert.equal(providerLabel('owm-precipitation'), 'Precipitation');
});
