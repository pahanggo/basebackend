<?php

use Gis\Casts\GeometryCast;
use Gis\Models\Feature;
use Gis\Models\Layer;
use Gis\Testing\RefreshesGisDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshesGisDatabase::class);

/**
 * Seed a grid of small parcels around Kuantan.
 *
 * Enough rows that the optimiser has a reason to prefer the index: against a
 * handful it will scan regardless, and the assertion would pass or fail on
 * table size rather than on the schema.
 */
function seedGrid(int $layerId, int $side = 40): void
{
    $rows = [];

    for ($i = 0; $i < $side; $i++) {
        for ($j = 0; $j < $side; $j++) {
            $lng = 103.20 + $i * 0.002;
            $lat = 3.70 + $j * 0.002;
            $maxx = $lng + 0.001;
            $maxy = $lat + 0.001;

            $wkt = sprintf(
                'POLYGON((%1$F %2$F, %3$F %2$F, %3$F %4$F, %1$F %4$F, %1$F %2$F))',
                $lng, $lat, $maxx, $maxy,
            );

            $rows[] = sprintf(
                '(%d, %s, %F, %F, %F, %F, %F, 5, %s, 1, NOW(), NOW())',
                $layerId,
                GeometryCast::literal(GeometryCast::toGeometry($wkt)),
                $lng, $lat, $maxx, $maxy,
                // A deterministic spread of sizes, so the area cull has
                // something to cull.
                100.0 * (1 + ($i + $j) % 50),
                "'{}'",
            );
        }
    }

    DB::connection(config('gis.connection'))->statement(
        'INSERT INTO gis_features (layer_id, geom, minx, miny, maxx, maxy, area_m2, vertex_count, properties, version, created_at, updated_at) VALUES '
        .implode(',', $rows)
    );
}

it('creates a spatial index, which requires NOT NULL and an SRID restriction', function () {
    $connection = config('gis.connection');

    $index = collect(DB::connection($connection)->select('SHOW INDEX FROM gis_features'))
        ->firstWhere('Key_name', 'sx_geom');

    expect($index)->not->toBeNull()
        ->and($index->Index_type)->toBe('SPATIAL');

    // The preconditions MySQL will not warn about.
    $column = collect(DB::connection($connection)->select('SHOW COLUMNS FROM gis_features'))
        ->firstWhere('Field', 'geom');

    expect($column->Null)->toBe('NO');

    $srid = DB::connection($connection)->selectOne(
        'SELECT SRS_ID FROM INFORMATION_SCHEMA.ST_GEOMETRY_COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['gis_features', 'geom'],
    );

    expect((int) $srid->SRS_ID)->toBe(4326);
});

it('uses the spatial index for the viewport query rather than scanning', function () {
    $layer = Layer::factory()->global()->create();
    seedGrid($layer->id);

    $query = Feature::query()
        ->where('layer_id', $layer->id)
        ->inBbox(103.22, 3.72, 103.24, 3.74);

    $plan = DB::connection(config('gis.connection'))->select(
        'EXPLAIN '.$query->toSql(),
        $query->getBindings(),
    );

    $row = $plan[0];

    expect($row->key)->toBe('sx_geom');
    expect($row->type)->toBe('range');
});

it('culls by area server-side, which is what holds the drawn set flat', function () {
    $layer = Layer::factory()->global()->create();
    seedGrid($layer->id);

    $all = Feature::query()->where('layer_id', $layer->id)->count();

    // Zooming out shrinks parcels below the pixel threshold faster than it
    // admits new ones, so the surviving set shrinks as the zoom falls.
    $atZoom16 = Feature::query()->where('layer_id', $layer->id)->areaCulled(16, 3.8)->count();
    $atZoom12 = Feature::query()->where('layer_id', $layer->id)->areaCulled(12, 3.8)->count();

    expect($all)->toBe(1600);
    expect($atZoom16)->toBeLessThanOrEqual($all);
    expect($atZoom12)->toBeLessThan($atZoom16);
});
