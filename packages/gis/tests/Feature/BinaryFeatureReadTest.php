<?php

use Gis\Http\Controllers\Api\FeatureReadController;
use Gis\Http\Encoders\BinaryFeatureEncoder;
use Gis\Models\Layer;
use Gis\Testing\RefreshesGisDatabase;

require_once __DIR__.'/../Helpers.php';

uses(RefreshesGisDatabase::class);

beforeEach(function () {
    seedParcels(Layer::factory()->global()->create(['name' => 'Lot']));
});

function readBinary(array $query = []): string
{
    $layer = Layer::query()->firstOrFail();

    $response = test()->actingAs(reader())
        ->withHeaders(['Accept' => FeatureReadController::BINARY_TYPE])
        ->get(route('gis.api.layers.features', [
            'layer' => $layer->id,
            ...array_merge(['bbox' => '103.0,3.7,103.6,3.9', 'zoom' => 18], $query),
        ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', FeatureReadController::BINARY_TYPE);

    return $response->getContent();
}

/** @return array<string, int> the decoded GIS1 header */
function header_(string $body): array
{
    $fields = unpack('a4magic/vversion/vflags/Vcount/Vrings/Vvertices/Vproperties', $body);

    $offsets = unpack('Vcoords/Vbbox/Vids/Varea/VringStarts/VfeatStarts/Vtypes/VpropertiesAt', substr($body, 24, 32));
    $tail = unpack('Vtotal/Vreserved', substr($body, 56, 8));

    return $fields + $offsets + $tail;
}

it('is negotiated by Accept, not by a format parameter', function () {
    $layer = Layer::query()->firstOrFail();

    // No Accept for the binary type: the readable encoding, every time.
    test()->actingAs(reader())
        ->get(route('gis.api.layers.features', ['layer' => $layer->id, 'bbox' => '103.0,3.7,103.6,3.9', 'zoom' => 18]))
        ->assertHeader('Content-Type', FeatureReadController::GEOJSON_TYPE);
});

it('starts with the magic and a version it can refuse', function () {
    $header = header_(readBinary());

    expect($header['magic'])->toBe(BinaryFeatureEncoder::MAGIC);
    expect($header['version'])->toBe(BinaryFeatureEncoder::VERSION);
});

it('counts what it contains', function () {
    $header = header_(readBinary());

    // Nine parcels, one ring each, five vertices each.
    expect($header['count'])->toBe(9);
    expect($header['rings'])->toBe(9);
    expect($header['vertices'])->toBe(45);
});

it('aligns every float section to eight bytes', function () {
    $header = header_(readBinary());

    foreach (['coords', 'bbox', 'ids', 'area'] as $section) {
        expect($header[$section] % 8)->toBe(0, "{$section} at offset {$header[$section]}");
    }

    // And the integer sections to four.
    foreach (['ringStarts', 'featStarts'] as $section) {
        expect($header[$section] % 4)->toBe(0, "{$section} at offset {$header[$section]}");
    }
});

it('declares a length that matches the body it sent', function () {
    $body = readBinary();

    expect(header_($body)['total'])->toBe(strlen($body));
});

it('lays its sections out in order, each exactly as long as its count implies', function () {
    $body = readBinary();
    $h = header_($body);

    expect($h['coords'])->toBe(BinaryFeatureEncoder::HEADER_BYTES);
    expect($h['bbox'] - $h['coords'])->toBe($h['vertices'] * 16);
    expect($h['ids'] - $h['bbox'])->toBe($h['count'] * 32);
    expect($h['area'] - $h['ids'])->toBe($h['count'] * 8);
    expect($h['ringStarts'] - $h['area'])->toBe($h['count'] * 8);
    expect($h['featStarts'] - $h['ringStarts'])->toBe(($h['rings'] + 1) * 4);
    expect($h['types'] - $h['featStarts'])->toBe(($h['count'] + 1) * 4);
    expect($h['propertiesAt'] - $h['types'])->toBe($h['count']);
});

it('closes both index arrays with a sentinel', function () {
    $body = readBinary();
    $h = header_($body);

    $ringStarts = array_values(unpack('V*', substr($body, $h['ringStarts'], ($h['rings'] + 1) * 4)));
    $featStarts = array_values(unpack('V*', substr($body, $h['featStarts'], ($h['count'] + 1) * 4)));

    expect($ringStarts[0])->toBe(0);
    expect(end($ringStarts))->toBe($h['vertices']);
    expect($featStarts[0])->toBe(0);
    expect(end($featStarts))->toBe($h['rings']);
});

it('writes coordinates longitude first, as little-endian doubles', function () {
    $body = readBinary();
    $h = header_($body);

    $first = unpack('elng/elat', substr($body, $h['coords'], 16));

    expect($first['lng'])->toBeGreaterThan(103.0)->toBeLessThan(104.0);
    expect($first['lat'])->toBeGreaterThan(3.0)->toBeLessThan(4.0);
});

it('agrees with the GeoJSON encoding of the same query', function () {
    $body = readBinary();
    $h = header_($body);

    $json = decodeStream(readViewport(['zoom' => 18]));

    expect($h['count'])->toBe(count($json['features']));

    $binaryIds = array_values(unpack('e*', substr($body, $h['ids'], $h['count'] * 8)));
    $jsonIds = array_map(fn (array $f) => (float) $f['id'], $json['features']);

    expect($binaryIds)->toBe($jsonIds);

    $binaryAreas = array_values(unpack('e*', substr($body, $h['area'], $h['count'] * 8)));
    $jsonAreas = array_map(fn (array $f) => (float) $f['properties']['_area'], $json['features']);

    expect($binaryAreas)->toBe($jsonAreas);

    // And the coordinates themselves, to the last bit: both come from the same
    // stored geometry, one as text and one as bytes.
    $firstJson = $json['features'][0]['geometry']['coordinates'][0][0];
    $firstBinary = unpack('elng/elat', substr($body, $h['coords'], 16));

    expect(round($firstBinary['lng'], 9))->toBe(round($firstJson[0], 9));
    expect(round($firstBinary['lat'], 9))->toBe(round($firstJson[1], 9));
});

it('carries no attribute tail unless attributes were asked for', function () {
    expect(header_(readBinary())['flags'])->toBe(0);
    expect(header_(readBinary())['properties'])->toBe(0);

    $with = header_(readBinary(['fields' => 1]));

    expect($with['flags'])->toBe(1);
    expect($with['properties'])->toBeGreaterThan(0);
});

it('culls and caps exactly as the readable encoding does', function () {
    config(['gis.read.max_features_per_response' => 3, 'gis.read.min_area_px' => 4]);

    expect(header_(readBinary())['count'])->toBe(3);
    expect(header_(readBinary(['zoom' => 12]))['count'])->toBe(3);

    config(['gis.read.max_features_per_response' => 20000]);

    expect(header_(readBinary(['zoom' => 12]))['count'])->toBe(4);
});
