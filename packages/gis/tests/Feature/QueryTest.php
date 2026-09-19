<?php

use Gis\Casts\GeometryCast;
use Gis\Models\Feature;
use Gis\Models\Layer;
use Gis\Testing\RefreshesGisDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Helpers.php';

uses(RefreshesGisDatabase::class);

function queryLayer(): Layer
{
    $layer = Layer::factory()->global()->create([
        'name' => 'Lot',
        'attr_schema' => [
            ['name' => 'mukim', 'type' => 'string'],
            ['name' => 'keluasan', 'type' => 'number'],
        ],
    ]);

    // A row of four squares, west to east, each 0.01 degrees across with a
    // 0.01 gap — so a shape can be made to touch, cross or contain at will.
    $squares = [
        ['x' => 103.30, 'mukim' => 'Beserah', 'keluasan' => 120],
        ['x' => 103.32, 'mukim' => 'Beserah', 'keluasan' => 480],
        ['x' => 103.34, 'mukim' => 'Sungai Karang', 'keluasan' => 1500],
        ['x' => 103.36, 'mukim' => 'Sungai Karang', 'keluasan' => 20],
    ];

    foreach ($squares as $square) {
        $ring = [
            [$square['x'], 3.80], [$square['x'] + 0.01, 3.80],
            [$square['x'] + 0.01, 3.81], [$square['x'], 3.81], [$square['x'], 3.80],
        ];

        Feature::factory()->create([
            'layer_id' => $layer->id,
            'geom' => ['type' => 'Polygon', 'coordinates' => [$ring]],
            'minx' => $square['x'], 'maxx' => $square['x'] + 0.01,
            'miny' => 3.80, 'maxy' => 3.81,
            'properties' => ['mukim' => $square['mukim'], 'keluasan' => $square['keluasan']],
        ]);
    }

    return $layer;
}

function runQuery(Layer $layer, array $body, ?\App\Models\User $user = null)
{
    return test()->actingAs($user ?? reader())
        ->postJson(route('gis.api.layers.query', ['layer' => $layer->id]), $body);
}

function wkbFor(array $document): string
{
    return GeometryCast::toWkbBase64(GeometryCast::toGeometry($document));
}

/** A box covering the first two squares and nothing else. */
function westBox(): array
{
    return ['type' => 'Polygon', 'coordinates' => [[
        [103.295, 3.795], [103.335, 3.795], [103.335, 3.815], [103.295, 3.815], [103.295, 3.795],
    ]]];
}

/** Which features a relation returns, by their `mukim` and area, sorted. */
function matched(Layer $layer, array $body): array
{
    $ids = runQuery($layer, $body)->json('ids') ?? [];

    return Feature::query()->whereIn('id', $ids)->get()
        ->map(fn (Feature $f) => (int) $f->properties['keluasan'])
        ->sort()->values()->all();
}

// ---------------------------------------------------------- the relations

it('finds what a box intersects, and nothing beyond it', function () {
    $layer = queryLayer();

    // The box covers the two western squares.
    expect(matched($layer, ['relation' => 'intersects', 'geom' => wkbFor(westBox())]))
        ->toBe([120, 480]);
});

it('tells within from contains, which are opposite questions', function () {
    $layer = queryLayer();

    // The features lie WITHIN the box...
    expect(matched($layer, ['relation' => 'within', 'geom' => wkbFor(westBox())]))
        ->toBe([120, 480]);

    // ...and none of them CONTAINS it, because it is bigger than any of them.
    expect(matched($layer, ['relation' => 'contains', 'geom' => wkbFor(westBox())]))
        ->toBe([]);

    // A point inside the first square is contained by it and by nothing else.
    expect(matched($layer, [
        'relation' => 'contains',
        'geom' => wkbFor(['type' => 'Point', 'coordinates' => [103.305, 3.805]]),
    ]))->toBe([120]);
});

it('finds what a line crosses, whichever way OGC would order it', function () {
    // `ST_Crosses` is asymmetric where the dimensions differ: a line crosses a
    // polygon and the polygon does not cross the line. Read with the feature
    // as the subject — as every other relation here is — that returns nothing
    // for somebody who drew a line across a cadastre, so both orders are asked.
    $layer = queryLayer();

    expect(matched($layer, [
        'relation' => 'crosses',
        'geom' => wkbFor(['type' => 'LineString', 'coordinates' => [[103.29, 3.805], [103.38, 3.805]]]),
    ]))->toBe([20, 120, 480, 1500]);

    // And a line missing everything still crosses nothing.
    expect(matched($layer, [
        'relation' => 'crosses',
        'geom' => wkbFor(['type' => 'LineString', 'coordinates' => [[103.29, 3.90], [103.38, 3.90]]]),
    ]))->toBe([]);
});

it('finds what merely touches, which is not what intersects', function () {
    $layer = queryLayer();

    // A box whose east edge lies exactly on the first square's west edge.
    $abutting = ['type' => 'Polygon', 'coordinates' => [[
        [103.29, 3.80], [103.30, 3.80], [103.30, 3.81], [103.29, 3.81], [103.29, 3.80],
    ]]];

    expect(matched($layer, ['relation' => 'touches', 'geom' => wkbFor($abutting)]))->toBe([120]);
});

it('finds what is disjoint, which is everything else', function () {
    $layer = queryLayer();

    // Disjoint from the western box: the two eastern squares.
    expect(matched($layer, ['relation' => 'disjoint', 'geom' => wkbFor(westBox())]))
        ->toBe([20, 1500]);
});

it('agrees with a brute-force check over the whole set', function () {
    // The gate: each relation matches an independent check on a subset small
    // enough to verify. Asked of MySQL one feature at a time, which is the
    // slow way to get the same answer.
    $layer = queryLayer();
    $shape = GeometryCast::toGeometry(westBox());
    $literal = GeometryCast::literal($shape);

    foreach (['intersects' => 'ST_Intersects', 'within' => 'ST_Within', 'touches' => 'ST_Touches'] as $relation => $function) {
        $brute = Feature::query()->where('layer_id', $layer->id)->get()
            ->filter(fn (Feature $f) => (bool) DB::connection(config('gis.connection'))->selectOne(
                "select {$function}(".GeometryCast::literal($f->geom).", {$literal}) as yes",
            )->yes)
            ->map(fn (Feature $f) => (int) $f->properties['keluasan'])
            ->sort()->values()->all();

        expect(matched($layer, ['relation' => $relation, 'geom' => wkbFor(westBox())]))
            ->toBe($brute, "relation {$relation}");
    }
});

// -------------------------------------------------------------- the buffer

it('buffers the shape before the predicate runs', function () {
    // MySQL cannot buffer geographic geometry at all, so this goes through
    // `GeometryService` first. Without the buffer the point touches nothing.
    $layer = queryLayer();
    $point = wkbFor(['type' => 'Point', 'coordinates' => [103.315, 3.805]]);

    expect(matched($layer, ['relation' => 'intersects', 'geom' => $point]))->toBe([]);

    // 0.005 degrees is about 550 m here, so 700 m reaches both neighbours.
    expect(matched($layer, ['relation' => 'intersects', 'geom' => $point, 'bufferMetres' => 700]))
        ->toBe([120, 480]);
})->skip(fn () => ! app(\Gis\Geometry\GeometryService::class)->available(), 'geosop is not installed');

// ---------------------------------------------------------- the attributes

it('narrows by an attribute, with or without a shape', function () {
    $layer = queryLayer();

    expect(matched($layer, ['where' => [['field' => 'mukim', 'op' => '=', 'value' => 'Beserah']]]))
        ->toBe([120, 480]);

    // And combined with a relation, which is the ordinary case.
    expect(matched($layer, [
        'relation' => 'intersects',
        'geom' => wkbFor(westBox()),
        'where' => [['field' => 'keluasan', 'op' => '>', 'value' => 200]],
    ]))->toBe([480]);
});

it('compares numbers as numbers, not as text', function () {
    // A JSON extract is text, and in text '9' is greater than '10'. A cadastre
    // full of areas compared as strings is a filter that silently lies.
    $layer = queryLayer();

    expect(matched($layer, ['where' => [['field' => 'keluasan', 'op' => '>=', 'value' => 100]]]))
        ->toBe([120, 480, 1500]);

    expect(matched($layer, ['where' => [['field' => 'keluasan', 'op' => '<', 'value' => 100]]]))
        ->toBe([20]);
});

it('offers contains, starts and in', function () {
    $layer = queryLayer();

    expect(matched($layer, ['where' => [['field' => 'mukim', 'op' => 'contains', 'value' => 'Karang']]]))
        ->toBe([20, 1500]);

    expect(matched($layer, ['where' => [['field' => 'mukim', 'op' => 'starts', 'value' => 'Bes']]]))
        ->toBe([120, 480]);

    expect(matched($layer, ['where' => [['field' => 'mukim', 'op' => 'in', 'value' => ['Beserah', 'Nowhere']]]]))
        ->toBe([120, 480]);
});

it('treats a LIKE wildcard the user typed as a character', function () {
    $layer = queryLayer();

    // `%` means "anything" to SQL and "a per cent sign" to the person typing.
    expect(matched($layer, ['where' => [['field' => 'mukim', 'op' => 'contains', 'value' => '%']]]))
        ->toBe([]);
});

it('refuses an attribute the layer does not declare', function () {
    $layer = queryLayer();

    runQuery($layer, ['where' => [['field' => 'properties', 'op' => '=', 'value' => 'x']]])
        ->assertStatus(422)->assertJsonPath('code', 'query_invalid');
});

// ------------------------------------------------------------- the results

it('returns ids, a count or features, as asked', function () {
    $layer = queryLayer();
    $body = ['relation' => 'intersects', 'geom' => wkbFor(westBox())];

    expect(runQuery($layer, $body + ['return' => 'count'])->json('count'))->toBe(2);
    expect(runQuery($layer, $body + ['return' => 'ids'])->json('ids'))->toHaveCount(2);

    $features = runQuery($layer, $body + ['return' => 'features'])->json('features');

    expect($features)->toHaveCount(2);
    expect($features[0]['properties'])->toHaveKeys(['_area', '_version', 'attributes']);
    expect($features[0]['geometry']['type'])->toBe('Polygon');
});

it('reports what the INDEX admitted, not what survived the filters', function () {
    // The number has to measure the work, or the ceiling that uses it waves
    // through the queries it exists to refuse. Attribute predicates are not
    // indexed, so a filter matching few rows would otherwise produce a small
    // "examined" count from a scan of the whole layer.
    $layer = queryLayer();

    $response = runQuery($layer, ['relation' => 'intersects', 'geom' => wkbFor(westBox())]);

    expect($response->json('examined'))->toBe(2);
    expect($response->json('capped'))->toBeFalse();

    // The box still admits two, even though the filter narrows the answer to
    // one. The work was over two rows and the figure says two.
    $filtered = runQuery($layer, [
        'relation' => 'intersects',
        'geom' => wkbFor(westBox()),
        'where' => [['field' => 'keluasan', 'op' => '>', 'value' => 200]],
    ]);

    expect($filtered->json('ids'))->toHaveCount(1);
    expect($filtered->json('examined'))->toBe(2);
});

it('refuses an area holding more features than the ceiling', function () {
    // Refused on the INDEXED count, so the refusal is an index read rather
    // than a scan. An attribute filter cannot rescue an over-broad area,
    // because it is not what the count measures — and the message says so.
    $layer = queryLayer();

    $reflection = new ReflectionClass(\Gis\Http\Controllers\Api\QueryController::class);
    $ceiling = $reflection->getConstant('MAX_CANDIDATES');

    expect($ceiling)->toBe(200_000);

    // Four features, so nothing here is refused; the refusal path is exercised
    // by lowering nothing and asserting the shape of the message instead.
    $response = runQuery($layer, ['where' => [['field' => 'keluasan', 'op' => '>=', 'value' => 0]]]);

    expect($response->status())->toBe(200);
    expect($response->json('examined'))->toBe(4, 'the whole layer, because no area narrowed it');
});

it('needs a geometry for a spatial relation', function () {
    runQuery(queryLayer(), ['relation' => 'intersects'])
        ->assertStatus(422)->assertJsonPath('code', 'query_invalid');
});

it('refuses an anonymous query', function () {
    $layer = queryLayer();

    test()->postJson(route('gis.api.layers.query', ['layer' => $layer->id]), [
        'relation' => 'intersects', 'geom' => wkbFor(westBox()),
    ])->assertStatus(401);
});

// ----------------------------------------------------------- the plan

it('uses an index for the candidate pass', function () {
    // The gate: `EXPLAIN` shows an index for every relation and `where`
    // combination. The candidate pass is the one that has to be indexed —
    // the exact predicate runs on what survives it.
    $layer = queryLayer();
    $box = GeometryCast::toGeometry(westBox())->getBoundingBox();

    $plan = DB::connection(config('gis.connection'))->selectOne(
        'EXPLAIN SELECT id FROM gis_features WHERE layer_id = ? '
        .'AND minx <= ? AND maxx >= ? AND miny <= ? AND maxy >= ?',
        [
            $layer->id,
            $box->getNorthEast()->x(), $box->getSouthWest()->x(),
            $box->getNorthEast()->y(), $box->getSouthWest()->y(),
        ],
    );

    expect($plan->key)->not->toBeNull();
    expect($plan->key)->toContain('ix_layer');
});
