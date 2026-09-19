<?php

use Gis\Models\Measurement;
use Gis\Models\MapUser;
use Gis\Testing\RefreshesGisDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Helpers.php';

uses(RefreshesGisDatabase::class);

/**
 * The client's geodesic answers, computed by `resources/js/lib/measure.js`.
 *
 * A fixture rather than a call into Node, so this test has no toolchain
 * dependency and the numbers are visible in the diff when they move. The loop
 * is closed at the other end: `tests/js/measure-fixture.test.mjs` recomputes
 * every figure from the module and fails if it has drifted, so the fixture
 * cannot silently stop representing the client.
 *
 * @return array{length: array<string, float>, area: array<string, float>}
 */
function clientFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/../fixtures/geodesic.json'), true);
}

/**
 * MySQL's own geodesic answer for a geometry, in metres or square metres.
 *
 * The gate. The client computes every measurement in JavaScript, from Vincenty
 * and an authalic-latitude area, and the only way to know it is right is to ask
 * something that computed it independently. MySQL on SRID 4326 uses geographic
 * semantics for these three, so it is a genuine second opinion rather than the
 * same arithmetic in another language.
 */
function serverMeasure(string $geoJson, string $what): float
{
    $function = match ($what) {
        'length' => 'ST_Length',
        'area' => 'ST_Area',
    };

    $row = DB::connection(config('gis.connection'))
        ->selectOne("SELECT {$function}(ST_GeomFromGeoJSON(?, 2, 4326)) AS v", [$geoJson]);

    return (float) $row->v;
}

/** A two-point line at a given latitude, a degree of longitude wide. */
function lineAt(float $lat, float $span = 1.0): array
{
    return ['type' => 'LineString', 'coordinates' => [[103.0, $lat], [103.0 + $span, $lat]]];
}

/** A one-degree box with its south edge at a given latitude. */
function boxAt(float $lat, float $span = 1.0): array
{
    return ['type' => 'Polygon', 'coordinates' => [[
        [103.0, $lat], [103.0 + $span, $lat],
        [103.0 + $span, $lat + $span], [103.0, $lat + $span], [103.0, $lat],
    ]]];
}

// -------------------------------------------------------------- the commands

it('saves a measurement against the map, with no layer involved', function () {
    [$map] = editableMap();

    sendCommands($map, [[
        'op' => 'measurement.create',
        'tempId' => 'msr-1',
        'kind' => 'distance',
        'geom' => json_encode(lineAt(3.8, 0.01)),
        'geomEncoding' => 'geojson',
        'value' => 1109.4,
        'unit' => 'si',
        'tool' => 'distance',
    ]])->assertOk();

    $measurement = Measurement::query()->where('map_id', $map->id)->sole();

    expect($measurement->kind)->toBe('distance');
    expect($measurement->unit)->toBe('si');
    // Which tool took it, which `kind` cannot say: radius and diameter are
    // both distances.
    expect($measurement->properties['tool'])->toBe('distance');
});

it('records which tool took it, so a radius and a diameter are told apart', function () {
    [$map] = editableMap();

    foreach (['radius', 'diameter'] as $index => $tool) {
        sendCommands($map, [[
            'op' => 'measurement.create',
            'tempId' => "msr-{$index}",
            'kind' => 'distance',
            'geom' => json_encode(lineAt(3.8, 0.01)),
            'geomEncoding' => 'geojson',
            'value' => 1109.4,
            'tool' => $tool,
        // A distinct seq per batch. `(clientId, seq)` is the idempotency key,
        // so a second batch reusing it is treated as a replay of the first and
        // returns its cached answer without applying anything.
        ]], null, ['seq' => $index + 1])->assertOk();
    }

    $tools = Measurement::query()->where('map_id', $map->id)->get()
        ->map(fn (Measurement $m) => $m->properties['tool'])->all();

    expect($tools)->toBe(['radius', 'diameter']);
});

it('refuses a kind the enum does not have', function () {
    [$map] = editableMap();

    sendCommands($map, [[
        'op' => 'measurement.create',
        'tempId' => 'msr-1',
        'kind' => 'radius',
        'geom' => json_encode(lineAt(3.8, 0.01)),
        'geomEncoding' => 'geojson',
    ]])->assertStatus(422);
});

it('refuses a measurement from a viewer', function () {
    [$map] = editableMap();

    $viewer = reader();

    MapUser::query()->create([
        'map_id' => $map->id,
        'user_id' => $viewer->id,
        'role' => 'viewer',
    ]);

    sendCommands($map, [[
        'op' => 'measurement.create',
        'tempId' => 'msr-1',
        'kind' => 'distance',
        'geom' => json_encode(lineAt(3.8, 0.01)),
        'geomEncoding' => 'geojson',
    ]], $viewer)->assertStatus(403);

    expect(Measurement::query()->where('map_id', $map->id)->count())->toBe(0);
});

it('renames one without touching its geometry, and guards the version', function () {
    [$map] = editableMap();

    sendCommands($map, [[
        'op' => 'measurement.create', 'tempId' => 'msr-1', 'kind' => 'area',
        'geom' => json_encode(boxAt(3.8, 0.01)), 'geomEncoding' => 'geojson', 'value' => 1.2e6,
    ]])->assertOk();

    $measurement = Measurement::query()->where('map_id', $map->id)->sole();

    sendCommands($map, [[
        'op' => 'measurement.update', 'id' => $measurement->id,
        'version' => $measurement->version, 'label' => 'Lot 44',
    ]], null, ['seq' => 2])->assertOk();

    expect($measurement->fresh()->label)->toBe('Lot 44');
    expect($measurement->fresh()->version)->toBe(2);

    // A stale write against a DIFFERENT field merges rather than conflicts:
    // the only change since was the label, so moving the geometry cannot be
    // overwriting anyone. That is the field-level guard working, not a missing
    // one, and the response says `merged` so the client can say so too.
    $merged = sendCommands($map, [[
        'op' => 'measurement.update', 'id' => $measurement->id,
        'version' => 1, 'geom' => json_encode(boxAt(4.8, 0.01)), 'geomEncoding' => 'geojson',
    ]], null, ['seq' => 3])->assertOk();

    expect($merged->json('applied.0.merged'))->toBeTrue();

    // The SAME field at a stale version is the case the guard exists for.
    sendCommands($map, [[
        'op' => 'measurement.update', 'id' => $measurement->id,
        'version' => 1, 'label' => 'Lot 45',
    ]], null, ['seq' => 4])->assertStatus(409);

    expect($measurement->fresh()->label)->toBe('Lot 44');
});

it('deletes one outright, because a measurement is a scratchpad entry', function () {
    // No soft delete and no 30-day restore, unlike a map or a layer. Those are
    // work someone would mourn; this is a number someone took while thinking.
    [$map] = editableMap();

    sendCommands($map, [[
        'op' => 'measurement.create', 'tempId' => 'msr-1', 'kind' => 'distance',
        'geom' => json_encode(lineAt(3.8, 0.01)), 'geomEncoding' => 'geojson',
    ]])->assertOk();

    $measurement = Measurement::query()->where('map_id', $map->id)->sole();

    sendCommands($map, [[
        'op' => 'measurement.delete', 'id' => $measurement->id,
        'version' => $measurement->version,
    ]], null, ['seq' => 2])->assertOk();

    expect(Measurement::query()->where('map_id', $map->id)->count())->toBe(0);
});

// -------------------------------------------------------------- the bootstrap

it('ships saved measurements with the map, geometry and all', function () {
    $owner = reader();
    [$map] = editableMap($owner);

    sendCommands($map, [[
        'op' => 'measurement.create', 'tempId' => 'msr-1', 'kind' => 'bearing',
        'geom' => json_encode(lineAt(3.8, 0.01)), 'geomEncoding' => 'geojson',
        'value' => 90.0, 'label' => 'To the jetty', 'tool' => 'bearing',
    ]])->assertOk();

    $bootstrap = \Gis\Http\Resources\MapBootstrap::make(
        $map->fresh(),
        \Gis\Support\MapAccess::resolve($map->fresh(), $owner->id),
    );

    expect($bootstrap['measurements']['truncated'])->toBeFalse();
    expect($bootstrap['measurements']['items'])->toHaveCount(1);

    $item = $bootstrap['measurements']['items'][0];

    // Geometry, which nothing else in this response carries: a measurement is
    // never read by viewport, so the bootstrap is the only place it arrives.
    expect($item['geom']['type'])->toBe('LineString');
    expect($item['geom']['coordinates'][0][0])->toBeGreaterThan(102.9);
    expect($item['label'])->toBe('To the jetty');
    expect($item['tool'])->toBe('bearing');
});

it('says so rather than silently showing a subset', function () {
    $owner = reader();
    [$map] = editableMap($owner);

    config()->set('gis.measurements.max_per_map', 2);

    for ($i = 0; $i < 3; $i++) {
        Measurement::query()->create([
            'map_id' => $map->id,
            'kind' => 'distance',
            'geom' => \Gis\Support\GeometryInput::parse(['geom' => json_encode(lineAt(3.8 + $i * 0.01, 0.01)), 'geomEncoding' => 'geojson']),
            'value' => 1.0,
            'unit' => 'si',
            'version' => 1,
        ]);
    }

    $bootstrap = \Gis\Http\Resources\MapBootstrap::make(
        $map->fresh(),
        \Gis\Support\MapAccess::resolve($map->fresh(), $owner->id),
    );

    expect($bootstrap['measurements']['truncated'])->toBeTrue();
    expect($bootstrap['measurements']['items'])->toHaveCount(2);
});

// --------------------------------------------------------------- the gate

it('agrees with the server within 0.1% on length, from the equator to 70°', function () {
    // The sweep the session file names. Planar measurement would pass at 0°
    // and fail progressively northwards, which is exactly why the sweep is the
    // gate rather than one comparison near the office.
    foreach ([0.0, 10.0, 25.0, 45.0, 60.0, 70.0] as $lat) {
        $geometry = lineAt($lat, 1.0);
        $server = serverMeasure(json_encode($geometry), 'length');

        expect($server)->toBeGreaterThan(0.0);

        $fixture = clientFixture()['length'][number_format($lat, 1, '.', '')];

        expect(abs($server - $fixture) / $server)
            ->toBeLessThan(0.001, "length at {$lat}°: server {$server}, client {$fixture}");
    }
});

it('agrees with the server within 0.1% on area, from the equator to 70°', function () {
    foreach ([0.0, 10.0, 25.0, 45.0, 60.0, 70.0] as $lat) {
        $geometry = boxAt($lat, 1.0);
        $server = serverMeasure(json_encode($geometry), 'area');
        $fixture = clientFixture()['area'][number_format($lat, 1, '.', '')];

        expect(abs($server - $fixture) / $server)
            ->toBeLessThan(0.001, "area at {$lat}°: server {$server}, client {$fixture}");
    }
});
