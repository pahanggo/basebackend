/**
 * Units and coordinate formats.
 *
 * The property that matters throughout is the round trip: whatever a user
 * types, reading it back has to give the same place. A conversion that is
 * nearly right is a position that is nearly right, and a parcel boundary in
 * nearly the right place is the failure this package can least afford.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    convert, format, formatDistance, formatArea, formatBearing, QUANTITIES,
} from '../../resources/js/lib/units.js';
import {
    parse, parseDecimal, parseDms, parseUtm, parseMgrs,
    formatDms, formatUtm, formatMgrs, latLngToUtm, utmToLatLng,
} from '../../resources/js/lib/coordinates.js';

const KUANTAN = { lng: 103.3260, lat: 3.8077 };

/** How far apart two positions are, in metres, near the equator. */
function metresApart(a, b) {
    const dLat = (a.lat - b.lat) * 111_320;
    const dLng = (a.lng - b.lng) * 111_320 * Math.cos((a.lat * Math.PI) / 180);

    return Math.hypot(dLat, dLng);
}

// ------------------------------------------------------------------- units

test('a figure is shown in the unit it reads best in', () => {
    // Between about one and a thousand of whatever it is written in, which is
    // the range a person can compare at a glance.
    assert.equal(formatDistance(900), '900 m');
    assert.equal(formatDistance(1500), '1.5 km');
    assert.equal(formatArea(500), '500 m²');
    assert.equal(formatArea(677131), '67.7131 ha');
    assert.equal(formatArea(5e6), '5 km²');
});

test('imperial is a different ladder, not a converted label', () => {
    assert.equal(formatDistance(1500, { system: 'imperial' }), '4921.3 ft');
    assert.equal(formatDistance(2000, { system: 'imperial' }), '1.243 mi');
    assert.equal(formatArea(677131, { system: 'imperial' }), '167.3227 ac');
});

test('one quantity can be overridden without moving the other', () => {
    // A survey office measures distance in metres and land in acres in the
    // same breath. A global toggle would be wrong half the time.
    const mixed = { system: 'si', area: 'rai' };

    assert.equal(formatDistance(1500, mixed), '1.5 km', 'distance stays SI');
    assert.equal(formatArea(1600, mixed), '1 rai');

    assert.equal(formatArea(1011.7141056, { area: 'rood' }), '1 rood');
    assert.equal(formatDistance(1852, { distance: 'nautical' }), '1 nmi');
    assert.equal(formatDistance(20.1168, { distance: 'chains' }), '1 ch');
});

test('conversion reports the number and the unit apart', () => {
    // So a table can right-align the figure and not the unit.
    const { value, unit } = convert(1500, 'distance');

    assert.equal(unit, 'km');
    assert.ok(Math.abs(value - 1.5) < 1e-9);
    assert.deepEqual(QUANTITIES, ['distance', 'area']);
});

test('an unknown quantity or system falls back rather than throwing', () => {
    assert.equal(format(10, 'weight'), '10 ');
    assert.equal(formatArea(500, { system: 'martian' }), '500 m²');
});

test('a bearing reads as a title document writes it', () => {
    assert.equal(formatBearing(0), "0°00'00\"");
    assert.equal(formatBearing(63.2625), "63°15'45\"");
    assert.equal(formatBearing(360), "0°00'00\"", 'wraps');
    assert.equal(formatBearing(-90), "270°00'00\"", 'and wraps the other way');
});

test('rounding seconds carries into minutes rather than printing sixty', () => {
    assert.equal(formatBearing(12.99999), "13°00'00\"");
});

// ------------------------------------------------------------ coordinates

test('decimal degrees are read latitude first, as a map reads them out', () => {
    const point = parseDecimal('3.8077, 103.3260');

    assert.equal(point.lat, 3.8077);
    assert.equal(point.lng, 103.3260);
    assert.equal(point.format, 'decimal');
});

test('every format round-trips to within its own rounding', () => {
    const cases = [
        [formatDms(KUANTAN.lng, KUANTAN.lat), 'dms', 4],
        [formatUtm(KUANTAN.lng, KUANTAN.lat), 'utm', 1],
        [formatMgrs(KUANTAN.lng, KUANTAN.lat), 'mgrs', 2],
        ['3.8077, 103.3260', 'decimal', 0.001],
    ];

    for (const [text, expected, tolerance] of cases) {
        const point = parse(text);

        assert.ok(point, `failed to parse ${text}`);
        assert.equal(point.format, expected, `parsed ${text} as ${point.format}`);
        assert.ok(
            metresApart(point, KUANTAN) <= tolerance,
            `${expected} came back ${metresApart(point, KUANTAN).toFixed(3)} m away`,
        );
    }
});

test('degrees, minutes and seconds are accepted however they are punctuated', () => {
    // A title document, a GPS and a spreadsheet each write it differently.
    for (const text of [
        `3°48'27.7"N 103°19'33.6"E`,
        '3 48 27.7 N 103 19 33.6 E',
        "3d48m27.7sN 103d19m33.6sE",
    ]) {
        const point = parseDms(text);

        assert.ok(point, `failed on ${text}`);
        assert.ok(metresApart(point, KUANTAN) < 5, text);
    }
});

test('the southern and western hemispheres come out negative', () => {
    const point = parseDms(`3°48'27.7"S 103°19'33.6"W`);

    assert.ok(point.lat < 0 && point.lng < 0);
});

test('UTM names its zone, and the zone decides the answer', () => {
    // The data extent straddles 102 degrees east, which is the boundary
    // between zones 47 and 48 — the same fact that decides the metric SRID.
    assert.equal(latLngToUtm(3.0, 101.5).zone, 47);
    assert.equal(latLngToUtm(3.0, 103.5).zone, 48);

    const round = utmToLatLng(48, true, 314108, 421052);

    assert.ok(metresApart(round, KUANTAN) < 1);
});

test('a southern UTM northing is offset so it never goes negative', () => {
    // Which is what keeps a grid reference unsigned.
    const south = latLngToUtm(-3.8077, 103.3260);

    assert.ok(south.northing > 9_000_000, `got ${south.northing}`);
    assert.ok(metresApart(utmToLatLng(48, false, south.easting, south.northing), { lng: 103.3260, lat: -3.8077 }) < 1);
});

test('MGRS is accepted spaced or unspaced', () => {
    const spaced = formatMgrs(KUANTAN.lng, KUANTAN.lat);
    const tight = spaced.replace(/\s+/g, '');

    assert.ok(metresApart(parseMgrs(spaced), KUANTAN) < 2);
    assert.ok(metresApart(parseMgrs(tight), KUANTAN) < 2);
});

test('a shorter MGRS reference is a coarser one, not a wrong one', () => {
    // Three digits per axis is 100 m precision, which is what a field report
    // gives. It must land in the right 100 m square, not somewhere else.
    const coarse = formatMgrs(KUANTAN.lng, KUANTAN.lat, 3);
    const point = parseMgrs(coarse);

    assert.ok(metresApart(point, KUANTAN) < 150, `${coarse} came back ${metresApart(point, KUANTAN).toFixed(0)} m away`);
});

test('nonsense is refused rather than guessed at', () => {
    for (const text of ['', '   ', 'hello', '999, 999', '3.8077', '61N 314108 421052', 'ZZ12 3456']) {
        assert.equal(parse(text), null, `accepted ${JSON.stringify(text)}`);
    }
});

test('an out-of-range position is refused even when well formed', () => {
    // `91, 10` is NOT out of range: 91 can only be a longitude, so the pair is
    // unambiguous and means lng 91, lat 10. That is the disambiguation rule
    // working, not a hole in the check.
    assert.deepEqual(
        { lng: parseDecimal('91, 10').lng, lat: parseDecimal('91, 10').lat },
        { lng: 91, lat: 10 },
    );

    // These have no reading that is a place.
    assert.equal(parseDecimal('91, 181'), null);
    assert.equal(parseDecimal('200, 300'), null);
    assert.equal(parseDecimal('10, 181'), null, 'neither order is in range');
});

// --------------------------------------------------------------- scale bar

test('a scale bar is labelled with a figure worth reading', async () => {
    const { roundToReadable } = await import('../../resources/js/ui/scale-bar.js');

    // 1, 2 or 5 times a power of ten. A bar labelled "137 m" is arithmetic the
    // reader has to do; one labelled "100 m" is a ruler.
    assert.equal(roundToReadable(137), 100);
    assert.equal(roundToReadable(340), 200);
    assert.equal(roundToReadable(999), 500);
    assert.equal(roundToReadable(1001), 1000);
    assert.equal(roundToReadable(9.9), 5);
    assert.equal(roundToReadable(1), 1);
    assert.equal(roundToReadable(0.4), 0.2);
});

test('a scale bar with nothing to measure returns zero rather than infinity', () => {
    // Reachable before the map has a size.
    return import('../../resources/js/ui/scale-bar.js').then(({ roundToReadable }) => {
        assert.equal(roundToReadable(0), 0);
        assert.equal(roundToReadable(-5), 0);
        assert.equal(roundToReadable(NaN), 0);
    });
});
