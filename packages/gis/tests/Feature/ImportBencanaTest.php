<?php

use Gis\Casts\GeometryCast;
use Gis\Models\Feature;
use Gis\Models\Layer;
use Gis\Models\Map;
use Gis\Models\MapLayer;
use Gis\Testing\RefreshesGisDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshesGisDatabase::class);

/**
 * A stand-in for `bencana`, built in the test's own source database.
 *
 * The real source is 1.4 million rows and is read-only, so what is asserted
 * here is the command's behaviour — axis order, the computed columns, the
 * rejection of invalid geometry, globality — rather than the volume. The volume
 * numbers are recorded in the session's Results.
 */
function seedSource(): void
{
    $source = DB::connection(config('gis.import.bencana.connection'));

    $source->statement('DROP TABLE IF EXISTS lots');
    $source->statement('DROP TABLE IF EXISTS usages');

    $source->statement(<<<'SQL'
        CREATE TABLE lots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            upi VARCHAR(255) NOT NULL,
            negeri VARCHAR(8) NULL, daerah VARCHAR(8) NULL, mukim VARCHAR(16) NULL,
            seksyen VARCHAR(16) NULL, no_lot VARCHAR(32) NULL,
            keluasan DOUBLE NULL,
            geometry POLYGON NOT NULL SRID 4326,
            created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
        )
        SQL);

    $source->statement(<<<'SQL'
        CREATE TABLE usages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lot_upi VARCHAR(255) NULL, kod_gtn VARCHAR(255) NULL, gunatanah1 VARCHAR(255) NULL,
            geometry POLYGON NOT NULL SRID 4326,
            created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
        )
        SQL);

    // A square roughly 110 m on a side near Kuantan, plus two smaller ones and
    // one bow-tie that ST_IsValid rejects.
    $rows = [
        ['0609010001643', 'A big lot', 0.001, 12_200.0],
        ['0609010001644', 'A small lot', 0.0002, 488.0],
        ['0609010001645', 'Another small lot', 0.0002, 488.0],
    ];

    foreach ($rows as $i => [$upi, $label, $side, $keluasan]) {
        $lng = 103.32 + $i * 0.01;
        $lat = 3.80;

        $source->statement(
            'INSERT INTO lots (upi, negeri, daerah, mukim, seksyen, no_lot, keluasan, geometry, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, '.GeometryCast::literal(GeometryCast::toGeometry(sprintf(
                'POLYGON((%1$F %2$F, %3$F %2$F, %3$F %4$F, %1$F %4$F, %1$F %2$F))',
                $lng, $lat, $lng + $side, $lat + $side,
            ))).', NOW(), NOW())',
            [$upi, '06', '09', '01', '00', $label, $keluasan],
        );
    }

    // Self-intersecting: valid WKT, invalid geometry. The real source has
    // 11,638 of these.
    $source->statement(
        'INSERT INTO lots (upi, keluasan, geometry, created_at, updated_at) VALUES (?, ?, '
        .GeometryCast::literal(GeometryCast::toGeometry(
            'POLYGON((103.40 3.80, 103.41 3.81, 103.41 3.80, 103.40 3.81, 103.40 3.80))'
        )).', NOW(), NOW())',
        ['0609010009999', 100.0],
    );

    $source->statement(
        'INSERT INTO usages (lot_upi, kod_gtn, gunatanah1, geometry, created_at, updated_at) VALUES (?, ?, ?, '
        .GeometryCast::literal(GeometryCast::toGeometry(
            'POLYGON((103.32 3.80, 103.321 3.80, 103.321 3.801, 103.32 3.801, 103.32 3.80))'
        )).', NOW(), NOW())',
        ['0609010001643', '01', 'Pertanian'],
    );
}

beforeEach(function () {
    seedSource();
});

it('imports a known upi with its coordinates the right way round', function () {
    $this->artisan('gis:import-bencana')->assertSuccessful();

    $feature = Feature::query()
        ->whereRaw("properties->>'\$.upi' = ?", ['0609010001643'])
        ->firstOrFail();

    $point = $feature->geom->exteriorRing()->pointN(1);

    // Longitude first. Swapped, this lands at latitude 103, which does not
    // exist, and MySQL would not have complained.
    expect($point->x())->toBeGreaterThan(103.0)->toBeLessThan(104.0);
    expect($point->y())->toBeGreaterThan(3.0)->toBeLessThan(4.0);

    // Attributes travel as one JSON document.
    expect($feature->properties['upi'])->toBe('0609010001643');
    expect($feature->properties['no_lot'])->toBe('A big lot');
    expect($feature->vertex_count)->toBe(5);
});

it('computes area geodesically, agreeing with the source keluasan', function () {
    $this->artisan('gis:import-bencana', ['--layer' => ['lots']])->assertSuccessful();

    $features = Feature::query()->get();

    foreach ($features as $feature) {
        $keluasan = (float) $feature->properties['keluasan'];

        expect(abs($feature->area_m2 - $keluasan) / $keluasan)->toBeLessThan(0.02);
    }
});

it('rejects invalid geometry rather than importing it silently', function () {
    $this->artisan('gis:import-bencana', ['--layer' => ['lots']])
        ->expectsOutputToContain('fail ST_IsValid')
        ->assertSuccessful();

    expect(Feature::count())->toBe(3);

    expect(Feature::query()->whereRaw("properties->>'\$.upi' = ?", ['0609010009999'])->exists())
        ->toBeFalse();

    expect(file_get_contents(storage_path('app/gis-import-rejects-lots.txt')))->toContain('4');
});

it('creates global layers, not layers owned by a map', function () {
    $this->artisan('gis:import-bencana')->assertSuccessful();

    $layers = Layer::query()->orderBy('name')->get();

    expect($layers->pluck('name')->all())->toBe(['Gunatanah', 'Lot']);
    expect($layers->pluck('owner_map_id')->unique()->all())->toBe([null]);
    expect($layers->firstWhere('name', 'Lot')->feature_count)->toBe(3);
    expect($layers->firstWhere('name', 'Lot')->locked)->toBeTrue();
    expect($layers->firstWhere('name', 'Lot')->extent)->not->toBeNull();
});

it('shares a layer into a second map without copying a feature', function () {
    $this->artisan('gis:import-bencana')->assertSuccessful();

    $lot = Layer::query()->where('name', 'Lot')->firstOrFail();
    $before = Feature::count();

    $map = Map::factory()->create();
    MapLayer::factory()->readOnly()->create(['map_id' => $map->id, 'layer_id' => $lot->id]);

    // The whole point of the identity/placement split: sharing copies nothing.
    expect(Feature::count())->toBe($before);
    expect($map->placements()->count())->toBe(1);
    expect($map->placements()->first()->access)->toBe('read');
});

it('refuses a second import rather than doubling the features', function () {
    $this->artisan('gis:import-bencana', ['--layer' => ['lots']])->assertSuccessful();

    // The failure this guards against is not hypothetical: an import
    // interrupted halfway leaves rows behind and no marker, and running it
    // again would silently double them.
    $this->artisan('gis:import-bencana', ['--layer' => ['lots']])
        ->expectsOutputToContain('already holds')
        ->assertSuccessful();

    expect(Feature::count())->toBe(3);

    $this->artisan('gis:import-bencana', ['--layer' => ['lots'], '--fresh' => true])->assertSuccessful();

    expect(Layer::query()->where('name', 'Lot')->count())->toBe(1);
    expect(Feature::count())->toBe(3);
});

it('resumes an interrupted import from the last source row it wrote', function () {
    $this->artisan('gis:import-bencana', ['--layer' => ['lots']])->assertSuccessful();

    // Simulate the interruption: drop everything after the first source row.
    $highest = Feature::query()
        ->orderByRaw("CAST(properties->>'\$._src' AS UNSIGNED)")
        ->first();

    Feature::query()->whereKeyNot($highest->id)->delete();
    expect(Feature::count())->toBe(1);

    $this->artisan('gis:import-bencana', ['--layer' => ['lots'], '--resume' => true])
        ->expectsOutputToContain('Resuming from source id')
        ->assertSuccessful();

    expect(Feature::count())->toBe(3);
    expect(Feature::query()->pluck('properties')->map(fn ($p) => $p['_src'])->sort()->values()->all())
        ->toBe([1, 2, 3]);
});

it('records the source row each feature came from', function () {
    $this->artisan('gis:import-bencana', ['--layer' => ['lots']])->assertSuccessful();

    $feature = Feature::query()->whereRaw("properties->>'\$.upi' = ?", ['0609010001643'])->firstOrFail();

    expect($feature->properties['_src'])->toBe(1);
});

it('imports in chunks smaller than the source', function () {
    $this->artisan('gis:import-bencana', ['--layer' => ['lots'], '--chunk' => 1])->assertSuccessful();

    expect(Feature::count())->toBe(3);
});

it('refuses when the source and target are on different servers', function () {
    config(['database.connections.bencana.host' => '10.255.255.1']);
    DB::purge('bencana');

    $this->artisan('gis:import-bencana')
        ->expectsOutputToContain('same MySQL server')
        ->assertFailed();
});
