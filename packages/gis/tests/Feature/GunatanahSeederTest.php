<?php

use Gis\Database\Seeders\GunatanahSeeder;
use Gis\Models\Feature;
use Gis\Models\Layer;
use Gis\Support\ImportLedger;
use Gis\Testing\RefreshesGisDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshesGisDatabase::class);

/**
 * A stand-in for the PLANMalaysia services.
 *
 * The real ones hold 2.86 million and 738 thousand features, so what is
 * asserted here is the seeder's behaviour — axis order, the computed columns,
 * provenance, globality, windowing and resume — rather than the volume.
 *
 * `Http::fake()` called twice MERGES stubs behind the first rather than
 * replacing it, so everything one test needs goes in one call, and the window
 * responses are computed from the request rather than queued.
 */
function fakeArcGis(int $count = 5, array $unservable = [], array $overrides = []): void
{
    $polygon = function (int $id): array {
        // Distinct, non-overlapping squares near Kuantan, each about 22 m on a
        // side. Longitude first, as GeoJSON is defined.
        $lng = 103.32 + $id * 0.001;
        $lat = 3.80;

        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [$lng, $lat], [$lng + 0.0002, $lat], [$lng + 0.0002, $lat + 0.0002],
                    [$lng, $lat + 0.0002], [$lng, $lat],
                ]],
            ],
            // Service field names, truncated as the services truncate them.
            // What lands in `properties` is the renamed form.
            'properties' => [
                'OBJECTID' => $id,
                'lot_upi' => '060901000'.$id,
                'kod_gtn' => 'HT000',
                'gunatanah1' => 'Hutan',
                'tahun_data' => 2024,
                'luas_hekta' => 0.05,
                'negeri_nam' => 'Pahang',
            ],
        ];
    };

    Http::fake(array_merge([
        '*/query' => function ($request) use ($count, $polygon, $unservable) {
            $body = [];
            parse_str($request->body(), $body);

            if (isset($body['outStatistics'])) {
                return Http::response([
                    'features' => [['attributes' => ['lo' => 1, 'hi' => $count, 'n' => $count]]],
                ]);
            }

            preg_match('/OBJECTID>=(\d+) AND OBJECTID<=(\d+)/', $body['where'] ?? '', $bounds);

            // One unservable record refuses the whole window it sits in, which
            // is what the real service does.
            foreach ($unservable as $id) {
                if ($id >= (int) $bounds[1] && $id <= (int) $bounds[2]) {
                    return Http::response(['error' => ['code' => 400, 'message' => 'Failed to execute query.']]);
                }
            }

            $features = [];

            for ($id = (int) $bounds[1]; $id <= min($count, (int) $bounds[2]); $id++) {
                $features[] = $polygon($id);
            }

            return Http::response(['type' => 'FeatureCollection', 'features' => $features]);
        },
    ], $overrides));
}

function seedGunatanah(array $only = ['gunatanah_zoning'], bool $fresh = false): void
{
    $seeder = new GunatanahSeeder;
    $seeder->only = $only;
    $seeder->fresh = $fresh;
    $seeder->run();
}

beforeEach(function () {
    Storage::fake(config('gis.import.arcgis.progress_disk'));
    config(['gis.import.arcgis.window' => 2, 'gis.import.arcgis.concurrency' => 2]);

    // The reject list is appended to, and lives outside the faked disk because
    // it is an operator artefact rather than import state. A stale one would
    // make the bisection test pass without bisecting anything.
    foreach (glob(storage_path('app/gis-arcgis-rejects-*.txt')) as $stale) {
        unlink($stale);
    }
});

it('describes every configured source completely enough to import it', function () {
    foreach (config('gis.import.arcgis.sources') as $key => $definition) {
        expect($definition)->toHaveKeys(['service', 'layer', 'name', 'attributes', 'style'], $key);
        expect($definition['attributes'])->not->toBeEmpty($key);

        $stored = [];

        foreach ($definition['attributes'] as $field => $attribute) {
            expect($attribute)->toHaveCount(2, "{$key}.{$field}");

            [$property, $type] = $attribute;

            // Stored property names become JSON keys and, once indexed,
            // generated column names. A source whose field list drifted into
            // something unquotable should fail here, not four hours into a run.
            expect($property)->toMatch(Gis\Support\PropertySanitizer::KEY_PATTERN);
            expect($property)->not->toBe(Gis\Support\PropertySanitizer::SOURCE_ID_KEY);
            expect($type)->toBeIn(['string', 'number']);

            $stored[] = $property;
        }

        // Two service fields mapped to one property would silently keep
        // whichever came last.
        expect($stored)->toBe(array_unique($stored), $key);
    }

    // Two layer names cannot collide: the layer is found by name, so a
    // duplicate would have two sources importing into one layer.
    $names = array_column(config('gis.import.arcgis.sources'), 'name');
    expect($names)->toBe(array_unique($names));
});

it('imports features with their coordinates the right way round', function () {
    fakeArcGis();
    seedGunatanah();

    $feature = Feature::query()->whereRaw("properties->>'\$._src' = ?", ['1'])->firstOrFail();
    $point = $feature->geom->exteriorRing()->pointN(1);

    // Swapped, this lands at latitude 103, which does not exist, and MySQL
    // would not have complained.
    expect($point->x())->toBeGreaterThan(103.0)->toBeLessThan(104.0);
    expect($point->y())->toBeGreaterThan(3.0)->toBeLessThan(4.0);
});

it('computes the columns the area cull and the viewport read depend on', function () {
    fakeArcGis();
    seedGunatanah();

    $feature = Feature::query()->whereRaw("properties->>'\$._src' = ?", ['1'])->firstOrFail();

    // ~22 m square, geodesically. A planar computation would return square
    // degrees, which is about 4e-8.
    expect($feature->area_m2)->toBeGreaterThan(400.0)->toBeLessThan(600.0);
    expect($feature->vertex_count)->toBe(5);
    expect($feature->minx)->toBeGreaterThan(103.0)->toBeLessThan(104.0);
    expect($feature->maxx)->toBeGreaterThan($feature->minx);
    expect($feature->maxy)->toBeGreaterThan($feature->miny);
});

it('keeps the source attributes and stamps provenance', function () {
    fakeArcGis();
    seedGunatanah();

    $feature = Feature::query()->whereRaw("properties->>'\$._src' = ?", ['3'])->firstOrFail();

    expect($feature->properties['lot_upi'])->toBe('0609010003');
    expect($feature->properties['tahun_data'])->toBe(2024);
    expect($feature->properties['_src'])->toBe(3);

    // Renamed on the way in. `gunatanah1`, `luas_hekta` and `negeri_nam` are
    // shapefile column names that fit in ten characters, and storing them would
    // make them what every popup and export says from now on.
    expect($feature->properties['gunatanah'])->toBe('Hutan');
    expect($feature->properties['luas_hektar'])->toBe(0.05);
    expect($feature->properties['negeri'])->toBe('Pahang');
    expect($feature->properties)->not->toHaveKeys(['gunatanah1', 'luas_hekta', 'negeri_nam']);

    // OBJECTID travels as `_src` and nowhere else; two names for one fact would
    // be two things to keep in step.
    expect($feature->properties)->not->toHaveKey('OBJECTID');
});

it('creates a global, locked layer with its extent and count rolled up', function () {
    fakeArcGis();
    seedGunatanah();

    $layer = Layer::query()->where('name', 'Gunatanah Zoning')->firstOrFail();

    expect($layer->owner_map_id)->toBeNull();
    expect($layer->locked)->toBeTrue();
    expect($layer->kind)->toBe('vector');
    expect($layer->feature_count)->toBe(5);
    expect($layer->extent)->not->toBeNull();

    // The schema describes what is stored, so the client never sees a service
    // field name.
    expect(collect($layer->attr_schema)->pluck('name'))->toContain('luas_hektar')->not->toContain('luas_hekta');
    expect(collect($layer->attr_schema)->firstWhere('name', 'tahun_data')['type'])->toBe('number');
});

it('imports both sources into two separate layers', function () {
    fakeArcGis();
    seedGunatanah(['gunatanah_semasa', 'gunatanah_zoning']);

    expect(Layer::query()->orderBy('name')->pluck('name')->all())
        ->toBe(['Gunatanah Semasa', 'Gunatanah Zoning']);

    expect(Feature::count())->toBe(10);
});

it('walks the whole OBJECTID range in windows', function () {
    fakeArcGis(count: 5);
    seedGunatanah();

    // Five features, windows of two: 1-2, 3-4, 5-6. The last window overruns
    // the range and must not lose the row inside it.
    expect(Feature::count())->toBe(5);
    expect(Feature::query()->pluck('properties')->map(fn ($p) => $p['_src'])->sort()->values()->all())
        ->toBe([1, 2, 3, 4, 5]);
});

it('resumes from the ledger rather than importing a window twice', function () {
    fakeArcGis();
    seedGunatanah();

    expect(Feature::count())->toBe(5);

    // A re-run of a finished import is a no-op, not a second copy.
    seedGunatanah();
    expect(Feature::count())->toBe(5);

    // Simulate an interruption: the last window's rows and its ledger line are
    // both gone, because they committed together or not at all.
    Feature::query()->whereRaw("CAST(properties->>'\$._src' AS UNSIGNED) >= 5")->delete();
    $ledger = ImportLedger::for('gunatanah_zoning');
    Storage::disk(config('gis.import.arcgis.progress_disk'))
        ->put('gis/arcgis/gunatanah_zoning.done', "1\n3\n");

    seedGunatanah();

    expect(Feature::count())->toBe(5);
});

it('refuses to append to a layer that holds features it has no ledger for', function () {
    fakeArcGis();
    seedGunatanah();

    Storage::disk(config('gis.import.arcgis.progress_disk'))->delete('gis/arcgis/gunatanah_zoning.done');

    expect(fn () => seedGunatanah())->toThrow(RuntimeException::class, 'no import ledger');

    expect(Feature::count())->toBe(5);
});

it('truncates and restarts ids when asked to establish a deployment', function () {
    fakeArcGis();
    seedGunatanah();

    $before = Layer::query()->where('name', 'Gunatanah Zoning')->firstOrFail();

    // A placement pointing at a layer that is about to be renumbered: it has to
    // go with it, or the map's tree names ids that now mean something else.
    Gis\Models\MapLayer::factory()->create([
        'map_id' => Gis\Models\Map::factory()->create()->id,
        'layer_id' => $before->id,
    ]);

    $seeder = new GunatanahSeeder;
    $seeder->only = ['gunatanah_zoning'];
    $seeder->truncate = true;
    $seeder->run();

    $after = Layer::query()->where('name', 'Gunatanah Zoning')->firstOrFail();

    expect($after->id)->toBe(1);
    expect(Feature::count())->toBe(5);
    expect(Feature::query()->min('id'))->toBe(1);
    expect(Gis\Models\MapLayer::count())->toBe(0);

    // The ledgers go too, or the re-import would skip every window and leave
    // the layers empty.
    expect(Storage::disk(config('gis.import.arcgis.progress_disk'))
        ->exists('gis/arcgis/gunatanah_zoning.done'))->toBeTrue();
});

it('replaces the layer on a fresh run rather than duplicating it', function () {
    fakeArcGis();
    seedGunatanah();

    seedGunatanah(fresh: true);

    expect(Layer::query()->where('name', 'Gunatanah Zoning')->count())->toBe(1);
    expect(Feature::count())->toBe(5);
});

it('raises rather than importing nothing when the service refuses outright', function () {
    Http::fake(['*/query' => Http::response(['error' => ['message' => 'Unable to complete operation.']])]);

    // The statistics query is the first thing asked and the first thing
    // refused, so the run stops before a single window is marked done.
    expect(fn () => seedGunatanah())->toThrow(RuntimeException::class, 'Unable to complete operation');

    expect(Feature::count())->toBe(0);
    expect(Storage::disk(config('gis.import.arcgis.progress_disk'))
        ->exists('gis/arcgis/gunatanah_zoning.done'))->toBeFalse();
});

it('bisects a refused window to keep everything but the record behind it', function () {
    // GTsemasa_06 OBJECTID 1460 behaves exactly like this: on its own the
    // service answers `Failed to execute query.`, and it poisons every window
    // containing it. Without the bisection that is a thousand features lost
    // per bad record, silently, with the window recorded as done.
    fakeArcGis(count: 5, unservable: [3]);

    config(['gis.import.arcgis.window' => 4]);

    seedGunatanah();

    expect(Feature::query()->pluck('properties')->map(fn ($p) => $p['_src'])->sort()->values()->all())
        ->toBe([1, 2, 4, 5]);

    expect(file_get_contents(storage_path('app/gis-arcgis-rejects-gunatanah_zoning.txt')))
        ->toContain('3');
});

it('drops a non-polygon rather than storing a feature whose area column would lie', function () {
    // One stub only: a second `Http::fake()` merges behind the first rather
    // than replacing it, so `fakeArcGis()` here would keep answering.
    Http::fake(['*/query' => function ($request) {
        $body = [];
        parse_str($request->body(), $body);

        if (isset($body['outStatistics'])) {
            return Http::response(['features' => [['attributes' => ['lo' => 1, 'hi' => 1, 'n' => 1]]]]);
        }

        return Http::response(['type' => 'FeatureCollection', 'features' => [[
            'type' => 'Feature',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[103.32, 3.80], [103.33, 3.81]]],
            'properties' => ['OBJECTID' => 1],
        ]]]);
    }]);

    seedGunatanah();

    expect(Feature::count())->toBe(0);
});
