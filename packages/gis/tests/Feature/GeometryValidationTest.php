<?php

use Gis\Casts\GeometryCast;
use Gis\Models\Feature;
use Gis\Testing\RefreshesGisDatabase;
use Gis\Validation\GeometryValidator;

require_once __DIR__.'/../Helpers.php';

uses(RefreshesGisDatabase::class);

/** A geometry through the validator, as coordinates. */
function normalised(array $document): array
{
    return GeometryValidator::normalise(GeometryCast::toGeometry($document))->toArray();
}

function polygon(array $ring): array
{
    return ['type' => 'Polygon', 'coordinates' => [$ring]];
}

/**
 * The message a blocked geometry is refused with.
 *
 * A helper rather than `toThrow`, because what matters about a block is what
 * it tells the user: "the geometry crosses itself" is actionable, "invalid
 * geometry" is not, and only the message distinguishes them.
 */
function blocked(callable $attempt): string
{
    try {
        $attempt();
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    test()->fail('Expected the geometry to be refused, but it was accepted.');
}

/** The same geometry as a command would carry it. */
function wkb(array $document): string
{
    return GeometryCast::toWkbBase64(GeometryCast::toGeometry($document));
}

// ------------------------------------------------------------- auto-fixes

it('closes a ring that was left open', function () {
    // A pointer never lands exactly on the first vertex, and a format round
    // trip can drop the repeat. Neither is a decision anyone made.
    $ring = normalised(polygon([[0, 0], [0, 1], [1, 1], [1, 0]]))[0];

    expect($ring[0])->toBe(end($ring));
    expect(count($ring) - 1)->toBe(4);
});

it('removes consecutive duplicate vertices, which is also the zero-length rule', function () {
    // A segment has zero length exactly when its ends are the same point, so
    // one pass covers both rules. Snapping onto the vertex just placed is the
    // ordinary way either arises.
    $ring = normalised(polygon([[0, 0], [0, 0], [0, 1], [0, 1], [1, 1], [1, 0], [0, 0]]))[0];

    expect(count($ring) - 1)->toBe(4);
});

it('does not strip the repeated vertex that closes a ring', function () {
    // Deduplication runs before closing for exactly this reason: run the other
    // way round and every closed ring arrives open.
    $ring = normalised(polygon([[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]]))[0];

    expect($ring[0])->toBe(end($ring));
});

it('winds the exterior counter-clockwise and holes clockwise', function () {
    $document = [
        'type' => 'Polygon',
        'coordinates' => [
            [[0, 0], [0, 10], [10, 10], [10, 0], [0, 0]],
            [[2, 2], [4, 2], [4, 4], [2, 4], [2, 2]],
        ],
    ];

    [$exterior, $hole] = normalised($document);

    // Shoelace: negative is counter-clockwise in longitude-latitude.
    $shoelace = function (array $ring) {
        $sum = 0.0;

        for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
            $sum += ($ring[$i + 1][0] - $ring[$i][0]) * ($ring[$i + 1][1] + $ring[$i][1]);
        }

        return $sum;
    };

    expect($shoelace($exterior))->toBeLessThan(0);
    expect($shoelace($hole))->toBeGreaterThan(0);
});

it('normalises winding idempotently, whichever way the ring arrived', function () {
    $clockwise = normalised(polygon([[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]]));
    $counter = normalised(polygon([[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]));

    expect($clockwise)->toBe($counter);
    expect(normalised(['type' => 'Polygon', 'coordinates' => $clockwise]))->toBe($clockwise);
});

// ----------------------------------------------------------------- blocks

it('blocks a ring that crosses itself', function () {
    expect(blocked(fn () => normalised(polygon([[0, 0], [1, 1], [1, 0], [0, 1], [0, 0]]))))
        ->toContain('crosses itself');
});

it('blocks a polygon with fewer than three distinct corners', function () {
    expect(blocked(fn () => normalised(polygon([[0, 0], [0, 1], [0, 0]]))))
        ->toContain('three distinct corners');
});

it('blocks a coordinate outside the valid range, and names it', function () {
    expect(blocked(fn () => normalised(['type' => 'Point', 'coordinates' => [400, 5]])))
        ->toContain('400');

    expect(blocked(fn () => normalised(['type' => 'Point', 'coordinates' => [10, 91]])))
        ->toContain('outside the valid range');
});

it('blocks a line with only one distinct point', function () {
    expect(blocked(fn () => normalised(['type' => 'LineString', 'coordinates' => [[1, 1], [1, 1]]])))
        ->toContain('two distinct points');
});

// -------------------------------------------- the server repeats the checks

it('refuses geometry the client would have blocked, independently', function () {
    // The command endpoint is a public API reached with a session cookie.
    // Nothing that arrives there has necessarily been through a drawing tool.
    [$map, $layer] = editableMap();

    sendCommands($map, [[
        'op' => 'feature.create', 'tempId' => 'tmp:1', 'layerId' => $layer->id,
        'geom' => wkb(polygon([[103.3, 3.8], [103.4, 3.9], [103.4, 3.8], [103.3, 3.9], [103.3, 3.8]])),
    ]])->assertStatus(422)->assertJsonPath('code', 'geometry_invalid');

    expect(Feature::count())->toBe(0);
});

it('stores the repaired geometry, not the one that was sent', function () {
    [$map, $layer] = editableMap();

    // Open, wound the wrong way, with a duplicate vertex in it.
    sendCommands($map, [[
        'op' => 'feature.create', 'tempId' => 'tmp:1', 'layerId' => $layer->id,
        'geom' => wkb(polygon([
            [103.30, 3.80], [103.30, 3.80], [103.30, 3.81], [103.31, 3.81], [103.31, 3.80],
        ])),
    ]])->assertOk();

    $ring = Feature::query()->firstOrFail()->geom->toArray()[0];

    expect($ring[0])->toBe(end($ring), 'stored closed');
    expect(count($ring) - 1)->toBe(4, 'stored without the duplicate');
});

it('still enforces the vertex cap before it does any of this work', function () {
    // The cap is free and validation is not, so it runs first.
    [$map, $layer] = editableMap();

    $ring = [];

    for ($i = 0; $i <= 60_000; $i++) {
        $ring[] = [103.3 + $i * 1e-7, 3.8];
    }

    $ring[] = [103.3, 3.81];
    $ring[] = $ring[0];

    sendCommands($map, [[
        'op' => 'feature.create', 'tempId' => 'tmp:1', 'layerId' => $layer->id,
        'geom' => wkb(polygon($ring)),
    ]])->assertStatus(422)->assertJsonPath('code', 'vertex_limit');
});
