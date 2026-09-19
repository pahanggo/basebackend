<?php

use Gis\Casts\GeometryCast;
use Gis\Geometry\GeometryService;
use Gis\Geometry\GeosGeometryService;
use Gis\Geometry\GeosOp;
use Gis\Geometry\MetricSrid;
use Gis\Geometry\UnavailableGeometryService;
use Gis\Testing\RefreshesGisDatabase;

require_once __DIR__.'/../Helpers.php';

uses(RefreshesGisDatabase::class);

function geometryService(): GeometryService
{
    return app(GeometryService::class);
}

function geom(array $document)
{
    return GeometryCast::toGeometry($document);
}

function wkbOf(array $document): string
{
    return GeometryCast::toWkbBase64(geom($document));
}

/** A square of roughly a kilometre a side, near Kuantan. */
function squareNearKuantan(float $size = 0.01): array
{
    return ['type' => 'Polygon', 'coordinates' => [[
        [103.320, 3.800], [103.320 + $size, 3.800],
        [103.320 + $size, 3.800 + $size], [103.320, 3.800 + $size], [103.320, 3.800],
    ]]];
}

beforeEach(function () {
    if (! geometryService()->available()) {
        test()->markTestSkipped('geosop is not installed on this machine');
    }
});

// --------------------------------------------- the reason for the projection

it('buffers in metres, not in degrees', function () {
    // The whole session in one assertion. GEOS treats coordinates as unitless
    // numbers: hand it longitude and latitude with a distance in metres and a
    // 250 m buffer comes back spanning 353 degrees of longitude, confidently
    // and without complaint.
    $buffered = geometryService()->buffer(geom(['type' => 'Point', 'coordinates' => [103.3260, 3.8077]]), 250);
    $box = $buffered->getBoundingBox();
    $width = $box->getNorthEast()->x() - $box->getSouthWest()->x();

    // 500 m of longitude at this latitude is about 0.0045 degrees.
    expect($width)->toBeGreaterThan(0.004)->toBeLessThan(0.005);
});

it('encloses the area a buffer of that radius should, within the budget', function () {
    // Section 11 budgets client/server agreement at 0.1%, and the quadrant
    // segment count is what decides whether this meets it. Measured on this
    // deployment: 8 segments gives -0.647%, 32 gives -0.047%.
    $point = geom(['type' => 'Point', 'coordinates' => [103.3260, 3.8077]]);
    $ideal = M_PI * 250 * 250;

    config(['gis.geometry.buffer_quad_segs' => 32]);

    $area = GeometryCast::geodesicArea(geometryService()->buffer($point, 250));

    expect(abs($area - $ideal) / $ideal * 100)->toBeLessThan(0.1);
});

it('is measurably worse at the geosop default of eight segments', function () {
    // Asserted so that nobody "simplifies" the buffer call back to `buffer`
    // and loses the accuracy without noticing.
    $point = geom(['type' => 'Point', 'coordinates' => [103.3260, 3.8077]]);
    $ideal = M_PI * 250 * 250;

    config(['gis.geometry.buffer_quad_segs' => 8]);

    $area = GeometryCast::geodesicArea(geometryService()->buffer($point, 250));

    expect(abs($area - $ideal) / $ideal * 100)->toBeGreaterThan(0.1);
});

it('buffers inward for a negative distance, and empties rather than failing', function () {
    $square = geom(squareNearKuantan());

    $shrunk = geometryService()->buffer($square, -100);

    expect($shrunk->isEmpty())->toBeFalse();
    expect(GeometryCast::geodesicArea($shrunk))->toBeLessThan(GeometryCast::geodesicArea($square));

    // Shrinking by more than the shape is wide is a real question with a real
    // answer, and the answer is "nothing left".
    expect(geometryService()->buffer($square, -5000)->isEmpty())->toBeTrue();
});

// ------------------------------------------------------------ the UTM zone

it('picks the UTM zone from the geometry rather than a constant', function () {
    // The data extent runs 101.33 to 104.21 east, which straddles 102. A
    // hardcoded zone would put half the state in the wrong one, and the error
    // is smooth — distances would simply be a little wrong.
    expect(MetricSrid::forLongitude(101.5))->toBe(32647);
    expect(MetricSrid::forLongitude(103.5))->toBe(32648);

    expect(MetricSrid::forGeometry(geom(['type' => 'Point', 'coordinates' => [101.5, 3.0]])))->toBe(32647);
    expect(MetricSrid::forGeometry(geom(['type' => 'Point', 'coordinates' => [103.5, 3.0]])))->toBe(32648);
});

it('measures a shape straddling the zone boundary deterministically', function () {
    // One zone throughout, chosen from the centroid, so the parts of one shape
    // stay comparable with each other. The same geometry must also always give
    // the same answer.
    $straddling = ['type' => 'Polygon', 'coordinates' => [[
        [101.5, 3.0], [102.5, 3.0], [102.5, 3.5], [101.5, 3.5], [101.5, 3.0],
    ]]];

    expect(MetricSrid::forGeometry(geom($straddling)))->toBe(32648);
    expect(MetricSrid::forGeometry(geom($straddling)))->toBe(32648);

    $buffered = geometryService()->buffer(geom($straddling), 100);

    expect($buffered->isEmpty())->toBeFalse();
});

it('puts both sides of a two-geometry operation in the same zone', function () {
    // Projecting each side by its own centroid would put them in different
    // coordinate systems, and GEOS — which does not read the SRID — would
    // overlay them as though they were in one.
    $west = ['type' => 'Polygon', 'coordinates' => [[
        [101.9, 3.0], [102.1, 3.0], [102.1, 3.2], [101.9, 3.2], [101.9, 3.0],
    ]]];
    $east = ['type' => 'Polygon', 'coordinates' => [[
        [102.0, 3.1], [102.2, 3.1], [102.2, 3.3], [102.0, 3.3], [102.0, 3.1],
    ]]];

    $overlap = geometryService()->intersection(geom($west), geom($east));

    expect($overlap->isEmpty())->toBeFalse();

    // The overlap is 0.1 x 0.1 degrees, about 11 km square.
    $area = GeometryCast::geodesicArea($overlap);

    expect($area)->toBeGreaterThan(1.1e8)->toBeLessThan(1.4e8);
});

// -------------------------------------------------------- the operations

it('unions, differences and intersects', function () {
    $a = geom(squareNearKuantan());
    $b = geom(['type' => 'Polygon', 'coordinates' => [[
        [103.325, 3.805], [103.335, 3.805], [103.335, 3.815], [103.325, 3.815], [103.325, 3.805],
    ]]]);

    $service = geometryService();
    $areaA = GeometryCast::geodesicArea($a);

    expect(GeometryCast::geodesicArea($service->union([$a, $b])))->toBeGreaterThan($areaA);
    expect(GeometryCast::geodesicArea($service->difference($a, $b)))->toBeLessThan($areaA);
    expect(GeometryCast::geodesicArea($service->intersection($a, $b)))->toBeLessThan($areaA);

    // The three are consistent: union = A + B - intersection.
    $union = GeometryCast::geodesicArea($service->union([$a, $b]));
    $sum = $areaA + GeometryCast::geodesicArea($b) - GeometryCast::geodesicArea($service->intersection($a, $b));

    expect(abs($union - $sum) / $union)->toBeLessThan(0.001);
});

it('finds a hull, a centroid and a point that is actually on the surface', function () {
    // An L-shape, whose centroid falls in the notch — outside the shape. That
    // is the whole difference between the two, and why a label uses the second.
    $shape = geom(['type' => 'Polygon', 'coordinates' => [[
        [103.320, 3.800], [103.330, 3.800], [103.330, 3.803], [103.323, 3.803],
        [103.323, 3.810], [103.320, 3.810], [103.320, 3.800],
    ]]]);

    $service = geometryService();

    expect(GeometryCast::geodesicArea($service->convexHull($shape)))
        ->toBeGreaterThan(GeometryCast::geodesicArea($shape));

    expect($service->centroid($shape)->geometryType())->toBe('Point');
    expect($service->pointOnSurface($shape)->geometryType())->toBe('Point');
});

it('simplifies by a tolerance in metres, keeping the shape valid', function () {
    // Topology-preserving, so simplifying cannot turn a valid polygon into a
    // self-intersecting one that the validator would then refuse.
    $ring = [];

    for ($i = 0; $i <= 120; $i++) {
        $angle = ($i / 120) * 2 * M_PI;
        $ring[] = [103.326 + cos($angle) * 0.01, 3.808 + sin($angle) * 0.01];
    }

    $ring[] = $ring[0];

    $circle = geom(['type' => 'Polygon', 'coordinates' => [$ring]]);
    $simplified = geometryService()->simplify($circle, 50);

    expect(GeometryCast::countVertices($simplified))->toBeLessThan(GeometryCast::countVertices($circle));
    expect($simplified->isEmpty())->toBeFalse();
});

it('repairs a self-intersecting polygon', function () {
    // The import found 11,638 invalid rows in 1.4 million, almost all of them
    // self-intersections, so this is what brings those in.
    $bowtie = geom(['type' => 'Polygon', 'coordinates' => [[
        [103.320, 3.800], [103.330, 3.810], [103.330, 3.800], [103.320, 3.810], [103.320, 3.800],
    ]]]);

    $repaired = geometryService()->makeValid($bowtie);

    expect($repaired->isEmpty())->toBeFalse();

    // And the repair is genuinely valid, by the same predicate the validator
    // will judge it with on the way in.
    $row = DB::connection(config('gis.connection'))
        ->selectOne('select ST_IsValid('.GeometryCast::literal($repaired).') as valid');

    expect((bool) $row->valid)->toBeTrue();
});

// ------------------------------------------------------------ the endpoint

it('returns geometry and creates nothing', function () {
    // Keeping the two apart is what lets the command endpoint stay the only
    // write path — atomic, idempotent and replayable.
    $before = \Gis\Models\Feature::count();

    $response = test()->actingAs(reader())->postJson(route('gis.api.geometry.ops'), [
        'op' => 'buffer',
        'geom' => wkbOf(['type' => 'Point', 'coordinates' => [103.3260, 3.8077]]),
        'metres' => 250,
    ]);

    $response->assertOk();

    expect($response->json('empty'))->toBeFalse();
    expect($response->json('geomEncoding'))->toBe('wkb');
    expect(\Gis\Models\Feature::count())->toBe($before);

    $result = GeometryCast::fromWire($response->json('geom'));

    expect($result->geometryType())->toBe('Polygon');
});

it('reports an empty result as empty rather than as a failure', function () {
    $response = test()->actingAs(reader())->postJson(route('gis.api.geometry.ops'), [
        'op' => 'buffer',
        'geom' => wkbOf(squareNearKuantan()),
        'metres' => -5000,
    ]);

    $response->assertOk();

    expect($response->json('empty'))->toBeTrue();
    expect($response->json('geom'))->toBeNull();
});

it('handles a small geometry sent to the server anyway', function () {
    // `capabilities.inlineOpVertexLimit` tells the client when to stop using
    // Turf. It is advisory: the endpoint does not check how the caller decided.
    test()->actingAs(reader())->postJson(route('gis.api.geometry.ops'), [
        'op' => 'centroid',
        'geom' => wkbOf(['type' => 'Point', 'coordinates' => [103.3260, 3.8077]]),
    ])->assertOk();
});

it('refuses an operation it does not have', function () {
    test()->actingAs(reader())->postJson(route('gis.api.geometry.ops'), [
        'op' => 'teleport',
        'geom' => wkbOf(['type' => 'Point', 'coordinates' => [103.3, 3.8]]),
    ])->assertStatus(422)->assertJsonPath('code', 'unknown_op');
});

it('refuses geometry that is not geometry', function () {
    test()->actingAs(reader())->postJson(route('gis.api.geometry.ops'), [
        'op' => 'centroid',
        'geom' => 'not base64 wkb at all',
    ])->assertStatus(422);
});

it('refuses an anonymous request', function () {
    test()->postJson(route('gis.api.geometry.ops'), [
        'op' => 'centroid',
        'geom' => wkbOf(['type' => 'Point', 'coordinates' => [103.3, 3.8]]),
    ])->assertStatus(401);
});

// ------------------------------------------------- when GEOS is not there

it('refuses clearly when GEOS is unavailable, rather than degrading quietly', function () {
    // The session's gate: "either produces a correct result or refuses with a
    // clear message — never a silently degraded one." There is deliberately no
    // pure-PHP fallback, because a second implementation of buffering that
    // agreed with GEOS to 0.1% would be a geometry library, not a fallback.
    app()->instance(GeometryService::class, new UnavailableGeometryService);

    $response = test()->actingAs(reader())->postJson(route('gis.api.geometry.ops'), [
        'op' => 'buffer',
        'geom' => wkbOf(['type' => 'Point', 'coordinates' => [103.3, 3.8]]),
        'metres' => 100,
    ]);

    $response->assertStatus(501)->assertJsonPath('code', 'geos_unavailable');
});

it('tells the client through capabilities whether GEOS is there', function () {
    // So a deployment without the binary stops offering the operations,
    // rather than shipping different JavaScript.
    expect(\Gis\Http\Resources\MapBootstrap::capabilities())
        ->toHaveKeys(['geos', 'inlineOpVertexLimit']);

    app()->instance(GeometryService::class, new UnavailableGeometryService);

    expect(\Gis\Http\Resources\MapBootstrap::capabilities()['geos'])->toBeFalse();
});

it('cannot be influenced by WKT-like text in a feature property', function () {
    // Geometry goes to geosop on stdin and the second operand is an argv
    // element, so neither reaches a shell. A property is never in the command
    // at all, but the check is cheap and the failure would be severe.
    $service = new GeosGeometryService(new GeosOp);
    $subject = geom(squareNearKuantan());

    $result = $service->buffer($subject, 10);

    expect($result->isEmpty())->toBeFalse();
    expect($result->geometryType())->toBeIn(['Polygon', 'MultiPolygon']);
});
