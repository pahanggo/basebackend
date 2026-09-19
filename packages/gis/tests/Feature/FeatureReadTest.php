<?php

use Gis\Http\Controllers\Api\FeatureReadController;
use Gis\Models\Feature;
use Gis\Models\Layer;
use Gis\Support\GeometryInput;
use Gis\Testing\RefreshesGisDatabase;

require_once __DIR__.'/../Helpers.php';

uses(RefreshesGisDatabase::class);

beforeEach(function () {
    seedParcels(Layer::factory()->global()->create(['name' => 'Lot']));
});

it('refuses a guest', function () {
    $layer = Layer::query()->firstOrFail();

    $this->get(route('gis.api.layers.features', ['layer' => $layer->id, 'bbox' => '103,3,104,4']))
        ->assertRedirect();
});

it('returns valid GeoJSON with longitude first', function () {
    $body = decodeStream(readViewport(['zoom' => 18]));

    expect($body['type'])->toBe('FeatureCollection');

    $coordinates = $body['features'][0]['geometry']['coordinates'][0][0];

    // Longitude, then latitude. Swapped, the first number would be 3.8 — and
    // nothing in the stack would have objected.
    expect($coordinates[0])->toBeGreaterThan(103.0)->toBeLessThan(104.0);
    expect($coordinates[1])->toBeGreaterThan(3.0)->toBeLessThan(4.0);
});

it('culls by area, and the threshold follows the zoom', function () {
    // Pinned, because the deployment's own value is a tuning decision and this
    // is testing the mechanism.
    config(['gis.read.min_area_px' => 4]);

    // At zoom 12 a pixel is about 38 m, so 4 px is roughly 5,800 m2: the
    // million-square-metre parcel and the three middling ones survive, the
    // five tiny ones do not.
    $twelve = decodeStream(readViewport(['zoom' => 12]));

    expect($twelve['cull']['areaThresholdM2'])->toBeGreaterThan(5000.0)->toBeLessThan(6500.0);
    expect($twelve['cull']['returned'])->toBe(4);

    // At zoom 18 a pixel is 0.6 m, so the threshold is under 2 m2 and
    // everything survives.
    $eighteen = decodeStream(readViewport(['zoom' => 18]));

    expect($eighteen['cull']['areaThresholdM2'])->toBeLessThan(2.0);
    expect($eighteen['cull']['returned'])->toBe(9);
});

it('suppresses the cull only when asked explicitly', function () {
    config(['gis.read.min_area_px' => 4]);

    expect(decodeStream(readViewport(['zoom' => 12, 'minArea' => 0]))['cull']['returned'])->toBe(9);
});

it('returns the largest features first, so a cap drops the least visible', function () {
    $body = decodeStream(readViewport(['zoom' => 18]));

    $areas = array_map(fn (array $f) => $f['properties']['_area'], $body['features']);

    expect($areas)->toBe(collect($areas)->sortDesc()->values()->all());
    expect((float) $areas[0])->toBe(1000000.0);
});

it('caps the response and says so', function () {
    config(['gis.read.max_features_per_response' => 3]);

    $body = decodeStream(readViewport(['zoom' => 18]));

    expect($body['cull']['capped'])->toBeTrue();
    expect($body['cull']['returned'])->toBe(3);
    expect($body['features'])->toHaveCount(3);

    // What fell off the end is the smallest, never something in the middle.
    expect((float) $body['cull']['smallestReturnedM2'])->toBe(9000.0);
});

it('sends no attributes unless they are asked for', function () {
    $without = decodeStream(readViewport(['zoom' => 18]));
    $with = decodeStream(readViewport(['zoom' => 18, 'fields' => 1]));

    expect($without['features'][0]['properties'])->toHaveKeys(['_area']);
    expect($without['features'][0]['properties'])->not->toHaveKey('attributes');
    expect($with['features'][0]['properties'])->toHaveKey('attributes');
});

it('returns the stored geometry at every zoom, never a reshaped copy', function () {
    // There is no level-of-detail geometry. A feature is returned with the
    // vertices it was imported with, or the area cull drops it whole.
    $low = decodeStream(readViewport(['zoom' => 18]));
    $high = decodeStream(readViewport(['zoom' => 16]));

    $ring = fn (array $c) => $c['features'][0]['geometry']['coordinates'][0];

    expect($ring($low))->toBe($ring($high));
    expect($low['cull'])->not->toHaveKey('simplified');
});

it('excludes features outside the bounding box', function () {
    expect(decodeStream(readViewport(['bbox' => '100.0,1.0,100.5,1.5', 'zoom' => 18]))['cull']['returned'])
        ->toBe(0);
});

it('rejects a bounding box it cannot use', function () {
    foreach (['', '1,2,3', '103,3,103,4', '999,3,1000,4', '103,3,104,4,5'] as $bbox) {
        readViewport(['bbox' => $bbox])->assertStatus(422);
    }
});

it('rejects a zoom outside the world', function () {
    readViewport(['zoom' => 99])->assertStatus(422);
});

it('leaves out everything the caller says it already holds', function () {
    // The nine parcels run west to east from 103.320 in steps of 0.002.
    $all = decodeStream(readViewport(['zoom' => 18]));

    expect($all['features'])->toHaveCount(9);

    // Claim the western half. Every parcel whose box meets it must be omitted.
    $some = decodeStream(readViewport([
        'zoom' => 18,
        'held' => '103.0,3.7,103.325,3.9',
    ]));

    $heldIds = array_map(fn (array $f) => $f['id'], array_slice($all['features'], 0, 0));

    expect(count($some['features']))->toBeLessThan(9);

    // And the two halves together must be the whole, with nothing counted twice.
    $west = decodeStream(readViewport(['zoom' => 18, 'bbox' => '103.0,3.7,103.325,3.9']));
    $ids = array_merge(
        array_map(fn (array $f) => $f['id'], $west['features']),
        array_map(fn (array $f) => $f['id'], $some['features']),
    );

    expect($ids)->toHaveCount(count(array_unique($ids)));
    expect(array_unique($ids))->toHaveCount(9);
});

it('excludes on intersection, not containment, so a straddling feature is never sent twice', function () {
    // A box whose edge cuts through the parcels rather than between them.
    $held = '103.0,3.7,103.3245,3.9';

    $first = decodeStream(readViewport(['zoom' => 18, 'bbox' => $held]));
    $second = decodeStream(readViewport(['zoom' => 18, 'held' => $held]));

    $firstIds = array_map(fn (array $f) => $f['id'], $first['features']);
    $secondIds = array_map(fn (array $f) => $f['id'], $second['features']);

    // A feature straddling the edge belongs to the first read, because that
    // read asked for everything meeting its box too. If the exclusion used
    // containment it would come back in the second read as well.
    expect(array_intersect($firstIds, $secondIds))->toBeEmpty();
});

it('refuses a held box that is not four numbers', function () {
    readViewport(['held' => '103.0,3.7,103.5'])->assertStatus(422);
});

it('draws points and lines, which have no area to be culled by', function () {
    // The area cull exists to drop parcels too small to make a mark, which is
    // a statement about POLYGONS. `area_m2` is 0 for a point and for a line,
    // so a threshold above zero silently removed every one of them at every
    // zoom: you could draw a line, watch it save, and never see it again.
    $layer = Layer::factory()->create();

    Feature::factory()->create([
        'layer_id' => $layer->id,
        'geom' => GeometryInput::parse([
            'geom' => json_encode(['type' => 'LineString', 'coordinates' => [[103.32, 3.80], [103.33, 3.81]]]),
            'geomEncoding' => 'geojson',
        ]),
        // Explicit, because the factory's default bbox is random and does not
        // follow an overridden geometry — the read filters on these columns,
        // not on `geom`.
        'minx' => 103.32, 'maxx' => 103.33, 'miny' => 3.80, 'maxy' => 3.81,
        'area_m2' => 0.0,
    ]);

    Feature::factory()->create([
        'layer_id' => $layer->id,
        'geom' => GeometryInput::parse([
            'geom' => json_encode(['type' => 'Point', 'coordinates' => [103.325, 3.805]]),
            'geomEncoding' => 'geojson',
        ]),
        'minx' => 103.325, 'maxx' => 103.325, 'miny' => 3.805, 'maxy' => 3.805,
        'area_m2' => 0.0,
    ]);

    $response = test()->actingAs(reader())
        ->withHeaders(['Accept' => FeatureReadController::GEOJSON_TYPE])
        ->get(route('gis.api.layers.features', [
            'layer' => $layer->id,
            // Zoom 18, where the threshold is well above zero — the case that
            // made the two disappear.
            'bbox' => '103.30,3.79,103.35,3.82',
            'zoom' => 18,
        ]));

    $body = decodeStream($response);
    $types = array_map(fn ($f) => $f['geometry']['type'], $body['features']);

    expect($types)->toContain('LineString');
    expect($types)->toContain('Point');
});
