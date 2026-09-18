<?php

use Gis\Models\Layer;
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

it('reads the simplified geometry below the editing zoom and the real one above', function () {
    expect(decodeStream(readViewport(['zoom' => 12]))['cull']['simplified'])->toBeTrue();
    expect(decodeStream(readViewport(['zoom' => 16]))['cull']['simplified'])->toBeFalse();
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
