/**
 * Unit tests for the pure client-side geometry code.
 *
 * Run by Node's built-in test runner, and asserted from Pest so the suite has
 * one entry point. There is no second test framework here: `node --test` ships
 * with Node, which the build already requires.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    buildGeometry,
    projectLng,
    projectLat,
    unprojectX,
    unprojectY,
    POINT,
    POLYGON,
} from '../../resources/js/map/geometry.js';
import { SpatialIndex, cullByArea } from '../../resources/js/map/spatial-index.js';

/** A square of `side` degrees with its lower-left corner at (lng, lat). */
function square(id, lng, lat, side, area) {
    return {
        id,
        geometry: {
            type: 'Polygon',
            coordinates: [[
                [lng, lat],
                [lng + side, lat],
                [lng + side, lat + side],
                [lng, lat + side],
                [lng, lat],
            ]],
        },
        properties: { _area: area },
    };
}

function grid(n, area = 1000) {
    const features = [];

    for (let i = 0; i < n; i++) {
        features.push(square(
            i + 1,
            103.3 + (i % 50) * 0.001,
            3.8 + Math.floor(i / 50) * 0.001,
            0.0005,
            typeof area === 'function' ? area(i) : area,
        ));
    }

    return { features };
}

test('projection round-trips and matches the slippy-map formula', () => {
    // Kuantan. The known values are the standard Web Mercator normalisation,
    // which is what Leaflet's EPSG3857 computes.
    const x = projectLng(103.326);
    const y = projectLat(3.8077);

    assert.ok(Math.abs(x - 0.7870166) < 1e-6, `x was ${x}`);
    assert.ok(Math.abs(y - 0.4894153) < 1e-6, `y was ${y}`);

    assert.ok(Math.abs(unprojectX(x) - 103.326) < 1e-9);
    assert.ok(Math.abs(unprojectY(y) - 3.8077) < 1e-9);
});

test('longitude and latitude are not swapped', () => {
    // The whole-system footgun, asserted at the one place the client could
    // reintroduce it: a point in Malaysia must project into the eastern half
    // of the world and just north of its middle.
    const x = projectLng(103.326);
    const y = projectLat(3.8077);

    assert.ok(x > 0.5 && x < 0.8, 'longitude 103E should be east of centre');
    assert.ok(y > 0.45 && y < 0.5, 'latitude 3.8N should be just north of the equator');
});

test('builds one feature per input with sentinels closing both index arrays', () => {
    const geometry = buildGeometry(grid(10));

    assert.equal(geometry.count, 10);
    assert.equal(geometry.featStarts.length, 11);
    assert.equal(geometry.featStarts[10], 10);            // one ring each
    assert.equal(geometry.ringStarts[10], geometry.coords.length / 2);
    assert.equal(geometry.types[0], POLYGON);
    assert.equal(geometry.ids[0], 1);
    assert.equal(geometry.area[0], 1000);
});

test('stores multi-part geometry as one feature with several rings', () => {
    const geometry = buildGeometry({
        features: [{
            id: 1,
            geometry: {
                type: 'MultiPolygon',
                coordinates: [
                    [[[103.3, 3.8], [103.31, 3.8], [103.31, 3.81], [103.3, 3.8]]],
                    [[[103.4, 3.9], [103.41, 3.9], [103.41, 3.91], [103.4, 3.9]]],
                ],
            },
            properties: {},
        }],
    });

    assert.equal(geometry.count, 1);
    assert.equal(geometry.featStarts[1] - geometry.featStarts[0], 2);
});

test('a point is a point, not a degenerate polygon', () => {
    const geometry = buildGeometry({
        features: [{ id: 1, geometry: { type: 'Point', coordinates: [103.3, 3.8] }, properties: {} }],
    });

    assert.equal(geometry.types[0], POINT);
    assert.equal(geometry.coords.length, 2);
});

test('the bounding box covers every vertex of its feature', () => {
    const geometry = buildGeometry(grid(25));

    for (let f = 0; f < geometry.count; f++) {
        for (let r = geometry.featStarts[f]; r < geometry.featStarts[f + 1]; r++) {
            for (let v = geometry.ringStarts[r]; v < geometry.ringStarts[r + 1]; v++) {
                assert.ok(geometry.coords[v * 2] >= geometry.bbox[f * 4]);
                assert.ok(geometry.coords[v * 2] <= geometry.bbox[f * 4 + 2]);
                assert.ok(geometry.coords[v * 2 + 1] >= geometry.bbox[f * 4 + 1]);
                assert.ok(geometry.coords[v * 2 + 1] <= geometry.bbox[f * 4 + 3]);
            }
        }
    }
});

test('the index returns exactly what a brute-force scan returns', () => {
    const geometry = buildGeometry(grid(500));
    const index = new SpatialIndex(geometry);

    for (let trial = 0; trial < 50; trial++) {
        const minX = projectLng(103.3 + Math.random() * 0.05);
        const maxX = minX + Math.random() * 0.0002;
        const minY = projectLat(3.82 - Math.random() * 0.05);
        const maxY = minY + Math.random() * 0.0002;

        const found = Array.from(index.search(minX, minY, maxX, maxY)).sort((a, b) => a - b);

        const brute = [];

        for (let f = 0; f < geometry.count; f++) {
            if (geometry.bbox[f * 4] <= maxX
                && geometry.bbox[f * 4 + 2] >= minX
                && geometry.bbox[f * 4 + 1] <= maxY
                && geometry.bbox[f * 4 + 3] >= minY) {
                brute.push(f);
            }
        }

        assert.deepEqual(found, brute, `trial ${trial}`);
    }
});

test('the cull drops everything below the threshold and nothing above it', () => {
    const geometry = buildGeometry(grid(200, (i) => i * 100));
    const index = new SpatialIndex(geometry);

    const candidates = index.search(0, 0, 1, 1);
    assert.equal(candidates.length, 200);

    const kept = cullByArea(geometry.area, candidates, 5000);

    for (let k = 0; k < kept.length; k++) {
        assert.ok(geometry.area[kept[k]] >= 5000);
    }

    let expected = 0;

    for (let f = 0; f < geometry.count; f++) {
        if (geometry.area[f] >= 5000) {
            expected++;
        }
    }

    assert.equal(kept.length, expected);
});

test('a threshold of zero culls nothing', () => {
    const geometry = buildGeometry(grid(50, 1));
    const index = new SpatialIndex(geometry);

    assert.equal(cullByArea(geometry.area, index.search(0, 0, 1, 1), 0).length, 50);
});

