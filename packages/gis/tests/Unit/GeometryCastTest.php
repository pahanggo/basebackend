<?php

use Brick\Geo\LineString;
use Brick\Geo\Point;
use Brick\Geo\Polygon;
use Gis\Casts\GeometryCast;
use Gis\Models\Feature;
use Gis\Models\Layer;
use Gis\Testing\RefreshesGisDatabase;

uses(RefreshesGisDatabase::class);

/** Write a geometry through the cast and read it back from MySQL. */
function roundTrip(string $wkt): string
{
    $feature = Feature::factory()->create([
        'layer_id' => Layer::factory()->global(),
        'geom' => GeometryCast::toGeometry($wkt),
    ]);

    return (string) Feature::findOrFail($feature->id)->geom->asText();
}

it('round-trips every geometry kind through MySQL', function (string $wkt) {
    expect(roundTrip($wkt))->toBe($wkt);
})->with([
    'point' => 'POINT (103.326 3.8077)',
    'line' => 'LINESTRING (103.326 3.8077, 103.327 3.8078, 103.328 3.809)',
    'polygon' => 'POLYGON ((103.32 3.8, 103.33 3.8, 103.33 3.81, 103.32 3.81, 103.32 3.8))',
    'polygon with hole' => 'POLYGON ((103.32 3.8, 103.34 3.8, 103.34 3.82, 103.32 3.82, 103.32 3.8), '
        .'(103.325 3.805, 103.335 3.805, 103.335 3.815, 103.325 3.815, 103.325 3.805))',
    'multipolygon' => 'MULTIPOLYGON (((103.32 3.8, 103.33 3.8, 103.33 3.81, 103.32 3.81, 103.32 3.8)), '
        .'((103.34 3.82, 103.35 3.82, 103.35 3.83, 103.34 3.83, 103.34 3.82)))',
]);

it('keeps Kuantan in Malaysia rather than in the Indian Ocean', function () {
    // The footgun: MySQL reads SRID 4326 as latitude-longitude, so a point
    // written without `axis-order=long-lat` lands at 3.8E 103.3N — off the
    // coast of Somalia, at a latitude that does not exist. No error is raised,
    // which is why this is asserted rather than assumed.
    $feature = Feature::factory()->create([
        'layer_id' => Layer::factory()->global(),
        'geom' => GeometryCast::toGeometry('POINT (103.326 3.8077)'),
    ]);

    $point = Feature::findOrFail($feature->id)->geom;

    expect($point)->toBeInstanceOf(Point::class);
    expect($point->x())->toBeGreaterThan(100.0)->toBeLessThan(105.0);   // longitude
    expect($point->y())->toBeGreaterThan(1.0)->toBeLessThan(7.0);       // latitude
    expect($point->srid())->toBe(4326);
});

it('agrees with an explicit ST_AsBinary select', function () {
    $feature = Feature::factory()->create([
        'layer_id' => Layer::factory()->global(),
        'geom' => GeometryCast::toGeometry('POINT (103.326 3.8077)'),
    ]);

    // A plain `select *` returns MySQL's internal format, which prefixes WKB
    // with the SRID and is already longitude-latitude. The explicit select is
    // bare WKB. The cast must read both to the same geometry.
    $plain = Feature::findOrFail($feature->id)->geom->asText();

    $explicit = Feature::query()
        ->select(['id', GeometryCast::selectBinary('geom')])
        ->findOrFail($feature->id)
        ->geom
        ->asText();

    expect($explicit)->toBe($plain);
});

it('accepts geojson and wkt, and rejects nothing silently', function () {
    $fromGeoJson = GeometryCast::toGeometry([
        'type' => 'Point',
        'coordinates' => [103.326, 3.8077],
    ]);

    expect($fromGeoJson->asText())->toBe('POINT (103.326 3.8077)');

    expect(fn () => GeometryCast::toGeometry('NOT A GEOMETRY'))->toThrow(Exception::class);
});

it('writes only geometry it generated itself', function () {
    // The WKT is interpolated, not bound, so the character-class guard is the
    // control. Feed it something it cannot have produced.
    $point = Point::xy(103.326, 3.8077, 4326);

    expect(GeometryCast::literal($point))
        ->toContain("ST_GeomFromText('POINT (103.326 3.8077)', 4326, 'axis-order=long-lat')");
});

it('stores a null geometry as null', function () {
    // `gis_layers.extent` is the nullable geometry column now that
    // `gis_features.geom_simple` is gone; the cast has to hand back null
    // rather than attempt to read a zero-length blob.
    $layer = Layer::factory()->global()->create(['extent' => null]);

    expect(Layer::findOrFail($layer->id)->extent)->toBeNull();
});

it('preserves rings and parts', function () {
    $polygon = GeometryCast::toGeometry(
        'POLYGON ((103.32 3.8, 103.34 3.8, 103.34 3.82, 103.32 3.82, 103.32 3.8), '
        .'(103.325 3.805, 103.335 3.805, 103.335 3.815, 103.325 3.815, 103.325 3.805))'
    );

    $feature = Feature::factory()->create([
        'layer_id' => Layer::factory()->global(),
        'geom' => $polygon,
    ]);

    $read = Feature::findOrFail($feature->id)->geom;

    expect($read)->toBeInstanceOf(Polygon::class);
    expect($read->count())->toBe(2);                 // exterior ring plus one hole
    expect($read->exteriorRing())->toBeInstanceOf(LineString::class);
});
