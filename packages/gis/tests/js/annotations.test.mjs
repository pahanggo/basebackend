/**
 * What a measurement is worth, and the fixture that ties it to the server.
 *
 * The second half is the important one. `tests/fixtures/geodesic.json` is what
 * the PHP gate compares MySQL's `ST_Length` and `ST_Area` against, and a
 * fixture nothing regenerates is a record of what the client used to do. These
 * tests recompute every figure in it from the live module, so drift is a
 * failure here rather than a silently passing gate over there.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import {
    geometryFor, valueFor, anchorOf, outlineOf, describe, TOOL_KIND,
} from '../../resources/js/map/measure/annotations.js';
import { length, polygonArea, distance } from '../../resources/js/lib/measure.js';

const fixture = JSON.parse(readFileSync(new URL('../fixtures/geodesic.json', import.meta.url)));

const LATS = [0, 10, 25, 45, 60, 70];

test('the fixture the server gate compares against is what this module computes', () => {
    for (const lat of LATS) {
        const key = lat.toFixed(1);

        assert.equal(
            length([[103.0, lat], [104.0, lat]]),
            fixture.length[key],
            `length at ${lat}° drifted from the fixture — regenerate it and re-read the gate`,
        );

        assert.equal(
            polygonArea([[[103, lat], [104, lat], [104, lat + 1], [103, lat + 1], [103, lat]]]),
            fixture.area[key],
            `area at ${lat}° drifted from the fixture`,
        );
    }
});

test('a diameter is stored as the whole line, so its length IS its value', () => {
    // The invariant the module exists to hold. Storing centre-to-edge with a
    // doubled value would be half a line and a number nobody could check.
    const centre = [103.32, 3.8];
    const edge = [103.33, 3.8];
    const geometry = geometryFor('diameter', [centre, edge]);

    assert.equal(geometry.coordinates.length, 2);

    const radius = distance(centre, edge);
    const value = valueFor('distance', geometry);

    assert.ok(Math.abs(value - radius * 2) / value < 1e-6, `${value} vs ${radius * 2}`);

    // And the centre really is the middle of it.
    const midpoint = anchorOf(geometry);

    assert.ok(Math.abs(midpoint[0] - centre[0]) < 1e-9, `${midpoint[0]}`);
});

test('a radius keeps the line the user drew', () => {
    const geometry = geometryFor('radius', [[103.32, 3.8], [103.33, 3.8]]);

    assert.deepEqual(geometry.coordinates[0], [103.32, 3.8]);
    assert.ok(Math.abs(valueFor('distance', geometry) - distance([103.32, 3.8], [103.33, 3.8])) < 1e-9);
});

test('an area closes its ring, and the closing vertex does not weight the label', () => {
    const square = [[103, 3.8], [103.01, 3.8], [103.01, 3.81], [103, 3.81]];
    const geometry = geometryFor('area', square);

    assert.equal(geometry.type, 'Polygon');
    assert.equal(geometry.coordinates[0].length, 5);
    assert.deepEqual(geometry.coordinates[0][4], geometry.coordinates[0][0]);

    const [lng, lat] = anchorOf(geometry);

    assert.ok(Math.abs(lng - 103.005) < 1e-9, `${lng}`);
    assert.ok(Math.abs(lat - 3.805) < 1e-9, `${lat}`);
});

test('it refuses to build a geometry from too few points', () => {
    assert.equal(geometryFor('distance', [[103, 3.8]]), null);
    assert.equal(geometryFor('area', [[103, 3.8], [103.01, 3.8]]), null);
    assert.equal(geometryFor('bearing', []), null);
    // A zero-length drag has no direction to travel back along.
    assert.equal(geometryFor('diameter', [[103, 3.8], [103, 3.8]]), null);
});

test('radius and diameter are both stored as distances, and say which tool took them', () => {
    assert.equal(TOOL_KIND.radius, 'distance');
    assert.equal(TOOL_KIND.diameter, 'distance');
    assert.equal(TOOL_KIND.area, 'area');
    assert.equal(TOOL_KIND.bearing, 'bearing');
});

test('a named measurement shows its name, an unnamed one shows its number', () => {
    const measurement = { kind: 'distance', value: 1500, label: null };

    assert.equal(describe(measurement), '1.5 km');
    assert.equal(describe({ ...measurement, label: 'To the jetty' }), 'To the jetty');

    // And the reader's own preference decides the unit, not the taker's.
    assert.equal(describe(measurement, { system: 'imperial' }), '4921.3 ft');
});

test('a bearing reads as degrees, minutes and seconds whatever the unit system', () => {
    assert.equal(describe({ kind: 'bearing', value: 90, label: null }, { system: 'imperial' }), `90°00'00"`);
});

test('the outline of a polygon is its ring, and of a line its own points', () => {
    assert.equal(outlineOf({ type: 'Polygon', coordinates: [[[0, 0], [1, 0], [0, 1], [0, 0]]] }).length, 4);
    assert.equal(outlineOf({ type: 'LineString', coordinates: [[0, 0], [1, 1]] }).length, 2);
    assert.deepEqual(outlineOf(null), []);
});
