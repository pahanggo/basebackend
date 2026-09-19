<?php

use Gis\Http\Controllers\Api\FeatureReadController;
use Gis\Http\Encoders\BinaryFeatureEncoder;
use Gis\Models\Layer;
use Gis\Testing\RefreshesGisDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Helpers.php';

uses(RefreshesGisDatabase::class);

beforeEach(function () {
    seedParcels(Layer::factory()->global()->create(['name' => 'Lot']));
});

/**
 * The whole framed response: every feature frame, plus the trailer's cull.
 *
 * @return array{frames: array<int, string>, cull: array<string, mixed>|null}
 */
function binaryStream(array $query = []): array
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

    $body = $response->streamedContent();
    $frames = [];
    $cull = null;
    $offset = 0;

    while ($offset < strlen($body)) {
        $kind = ord($body[$offset]);
        $length = unpack('V', substr($body, $offset + 1, 4))[1];
        $payload = substr($body, $offset + 5, $length);
        $offset += 5 + $length;

        if ($kind === FeatureReadController::FRAME_TRAILER) {
            $cull = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            break;
        }

        $frames[] = $payload;
    }

    expect($offset)->toBe(strlen($body), 'the frames should account for every byte');

    return ['frames' => $frames, 'cull' => $cull];
}

/**
 * One frame's `GIS1` document.
 *
 * The layout assertions below are about one document, and a document is what a
 * frame carries; the fixture is nine parcels against a chunk of 100, so unless
 * a test says otherwise there is exactly one.
 */
function readBinary(array $query = []): string
{
    $frames = binaryStream($query)['frames'];

    expect($frames)->toHaveCount(1);

    return $frames[0];
}

/** @return array<string, int> the decoded GIS1 header */
function header_(string $body): array
{
    $fields = unpack('a4magic/vversion/vflags/Vcount/Vrings/Vvertices/Vproperties', $body);

    $offsets = unpack(
        'Vcoords/Vbbox/Vids/Varea/VringStarts/VfeatStarts/Vtypes/Vclasses/VclassDict/VpropertiesAt',
        substr($body, 24, 40),
    );
    $tail = unpack('Vtotal/VcoordExponent/VclassDictLength/Vreserved', substr($body, 64, 16));

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

it('frames the stream so a reader knows how much to buffer', function () {
    config(['gis.read.stream_chunk' => 2]);

    $stream = binaryStream();

    // Nine parcels in twos: four full frames and a remainder. An empty frame is
    // never written, so nine does not become five plus a zero.
    expect($stream['frames'])->toHaveCount(5);

    $counts = array_map(fn (string $frame) => header_($frame)['count'], $stream['frames']);

    expect($counts)->toBe([2, 2, 2, 2, 1]);
});

it('gives every frame a header of its own, with its own offsets', function () {
    config(['gis.read.stream_chunk' => 4]);

    foreach (binaryStream()['frames'] as $frame) {
        $header = header_($frame);

        expect($header['magic'])->toBe(BinaryFeatureEncoder::MAGIC);
        expect($header['coords'])->toBe(BinaryFeatureEncoder::HEADER_BYTES);
        expect($header['total'])->toBe(strlen($frame));
    }
});

it('carries the cull in a trailer, which is the only place it can be', function () {
    // Not a header: `returned`, `capped` and `smallestReturnedM2` are only known
    // once the last row has been read, and headers are written before the first.
    $stream = binaryStream();

    expect($stream['cull'])->not->toBeNull();
    expect($stream['cull']['returned'])->toBe(9);
    expect($stream['cull']['capped'])->toBeFalse();
    // JSON has one number type, so a whole-numbered area comes back an int.
    expect($stream['cull']['smallestReturnedM2'])->toEqual(60.0);
});

it('reports the same cull however the features were divided up', function () {
    $whole = binaryStream()['cull'];

    config(['gis.read.stream_chunk' => 1]);

    expect(binaryStream()['cull'])->toEqual($whole);
});

it('stops at the cap mid-chunk, and says so in the trailer', function () {
    config(['gis.read.max_features_per_response' => 5, 'gis.read.stream_chunk' => 2]);

    $stream = binaryStream();

    expect(array_map(fn (string $f) => header_($f)['count'], $stream['frames']))->toBe([2, 2, 1]);
    expect($stream['cull']['returned'])->toBe(5);
    expect($stream['cull']['capped'])->toBeTrue();
});

it('quantises coordinates below the editing zoom, and not at it', function () {
    $low = header_(readBinary(['zoom' => 12]));
    $edit = header_(readBinary(['zoom' => 18]));

    expect($low['flags'] & BinaryFeatureEncoder::FLAG_QUANTISED)->toBe(BinaryFeatureEncoder::FLAG_QUANTISED);
    expect($low['coordExponent'])->toBe((int) config('gis.read.coord_exponent'));
    expect($low['bbox'] - $low['coords'])->toBe($low['vertices'] * 8);

    // At the editing zoom a coordinate can be dragged and sent back, so
    // rounding it on the way out would write the rounding into storage.
    expect($edit['flags'] & BinaryFeatureEncoder::FLAG_QUANTISED)->toBe(0);
    expect($edit['coordExponent'])->toBe(0);
    expect($edit['bbox'] - $edit['coords'])->toBe($edit['vertices'] * 16);
});

it('halves the coordinate section without moving a vertex further than the exponent allows', function () {
    $body = readBinary(['zoom' => 12]);
    $h = header_($body);

    $packed = array_values(unpack('V*', substr($body, $h['coords'], $h['vertices'] * 8)));

    // The same geometry, straight from MySQL, in the same order.
    $stored = DB::connection(config('gis.connection'))
        ->table('gis_features')
        ->where('layer_id', Layer::query()->firstOrFail()->id)
        ->orderByDesc('area_m2')
        ->selectRaw('ST_AsBinary(geom, \'axis-order=long-lat\') AS wkb')
        ->pluck('wkb');

    $expected = [];

    foreach ($stored as $wkb) {
        $rings = unpack('V', substr($wkb, 5, 4))[1];
        $offset = 9;

        for ($r = 0; $r < $rings; $r++) {
            $points = unpack('V', substr($wkb, $offset, 4))[1];
            $offset += 4;

            foreach (unpack('e'.($points * 2), substr($wkb, $offset, $points * 16)) as $ordinate) {
                $expected[] = $ordinate;
            }

            $offset += $points * 16;
        }
    }

    // The zoom-12 cull drops the small parcels, and both lists are ordered by
    // area descending, so the response is a prefix of the stored ordinates.
    expect(count($packed))->toBeLessThan(count($expected));

    $scale = 10 ** (int) config('gis.read.coord_exponent');
    $worst = 0.0;

    foreach ($packed as $i => $value) {
        $bias = $i % 2 === 0 ? BinaryFeatureEncoder::LNG_BIAS : BinaryFeatureEncoder::LAT_BIAS;
        $worst = max($worst, abs(($value / $scale) - $bias - $expected[$i]));
    }

    // Rounding to the nearest step cannot move a coordinate further than half
    // of one, whatever the exponent is set to.
    expect($worst)->toBeLessThanOrEqual(0.5 / $scale);
});

it('keeps every ordinate inside the range a biased uint32 affords', function () {
    $body = readBinary(['zoom' => 12]);
    $h = header_($body);

    $ceiling = 360 * (10 ** (int) config('gis.read.coord_exponent'));

    // 360 degrees of longitude at the finest exponent this can take, 1e-7, is
    // 3.6e9 — inside uint32. Anything beyond would have wrapped silently.
    expect($ceiling)->toBeLessThanOrEqual(4_294_967_295);

    foreach (unpack('V*', substr($body, $h['coords'], $h['vertices'] * 8)) as $value) {
        expect($value)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($ceiling);
    }
});
