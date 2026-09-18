<?php

use Gis\Models\CommandLog;
use Gis\Models\Feature;
use Gis\Models\Layer;
use Gis\Models\Map;
use Gis\Models\MapLayer;
use Gis\Models\MapUser;
use Gis\Models\Measurement;
use Gis\Testing\RefreshesGisDatabase;

uses(RefreshesGisDatabase::class);

it('applies a batch atomically and returns a version per affected row', function () {
    [$map, $layer] = editableMap();

    $response = sendCommands($map, [
        ['op' => 'feature.create', 'tempId' => 'tmp:1', 'layerId' => $layer->id, 'geom' => wkbSquare(), 'properties' => ['status' => 'active']],
        ['op' => 'layer.rename', 'id' => $layer->id, 'version' => 1, 'name' => 'Lot Baharu'],
    ]);

    $response->assertOk();

    expect($response->json('mapVersion'))->toBe(2);
    expect($response->json('applied.0.tempId'))->toBe('tmp:1');
    expect($response->json('applied.0.version'))->toBe(1);
    expect($response->json('applied.1.version'))->toBe(2);

    expect(Feature::count())->toBe(1);
    expect($layer->fresh()->name)->toBe('Lot Baharu');
    expect($layer->fresh()->feature_count)->toBe(1);
});

it('applies nothing when one command in the batch fails', function () {
    // The load-bearing property: the client never reasons about partial
    // application, so it may apply optimistically and roll back whole.
    [$map, $layer] = editableMap();

    $response = sendCommands($map, [
        ['op' => 'feature.create', 'tempId' => 'tmp:1', 'layerId' => $layer->id, 'geom' => wkbSquare()],
        ['op' => 'feature.create', 'tempId' => 'tmp:2', 'layerId' => $layer->id, 'geom' => 'not base64 wkb'],
    ]);

    $response->assertStatus(422)->assertJsonPath('code', 'geometry_invalid');

    expect(Feature::count())->toBe(0);
    expect($map->fresh()->version)->toBe(1);
    expect(CommandLog::count())->toBe(0);
});

it('replays an identical (clientId, seq) instead of applying it twice', function () {
    [$map, $layer] = editableMap();

    $command = [['op' => 'feature.create', 'tempId' => 'tmp:1', 'layerId' => $layer->id, 'geom' => wkbSquare()]];

    $first = sendCommands($map, $command);
    $second = sendCommands($map, $command);

    $first->assertOk();
    $second->assertOk();

    // The original answer, including the id it assigned — a retry after a
    // timeout must not observe a different world than the call it is retrying.
    // Compared by value rather than identity because MySQL reorders the keys of
    // a JSON object on storage, which is a storage detail and not a difference
    // any client can see.
    expect($second->json('applied'))->toEqual($first->json('applied'));
    expect($second->json('mapVersion'))->toBe($first->json('mapVersion'));
    expect($second->json('replayed'))->toBeTrue();

    expect(Feature::count())->toBe(1);
    expect(CommandLog::count())->toBe(1);
});

it('returns 409 with server state and applies nothing on a real conflict', function () {
    [$map, $layer] = editableMap();

    $feature = Feature::factory()->create(['layer_id' => $layer->id, 'properties' => ['status' => 'active']]);

    // Someone else moves it to version 2, touching `status`.
    sendCommands($map, [
        ['op' => 'feature.update', 'id' => $feature->id, 'version' => 1, 'properties' => ['status' => 'pending']],
    ])->assertOk();

    $response = sendCommands($map, [
        ['op' => 'feature.update', 'id' => $feature->id, 'version' => 1, 'properties' => ['status' => 'closed']],
    ], envelope: ['seq' => 2]);

    $response->assertStatus(409)
        ->assertJsonPath('code', 'version_conflict')
        ->assertJsonPath('conflicts.0.id', $feature->id)
        ->assertJsonPath('conflicts.0.yourVersion', 1)
        ->assertJsonPath('conflicts.0.serverVersion', 2);

    expect($response->json('conflicts.0.server.properties.status'))->toBe('pending');
    expect($response->headers->get('Content-Type'))->toContain('application/problem+json');

    // Nothing applied: the losing write did not land.
    expect($feature->fresh()->properties['status'])->toBe('pending');
});

it('merges two clients editing different fields of one feature', function () {
    // The machinery co-editing needs, proved on the rare v1 conflict rather
    // than introduced when conflicts are routine (specification section 16).
    [$map, $layer] = editableMap();

    $feature = Feature::factory()->create([
        'layer_id' => $layer->id,
        'properties' => ['status' => 'active', 'owner' => 'aina'],
    ]);

    sendCommands($map, [
        ['op' => 'feature.update', 'id' => $feature->id, 'version' => 1, 'properties' => ['status' => 'pending']],
    ])->assertOk();

    // Still holding version 1, but writing a field the other side left alone.
    $response = sendCommands($map, [
        ['op' => 'feature.update', 'id' => $feature->id, 'version' => 1, 'properties' => ['owner' => 'zaki']],
    ], envelope: ['seq' => 2]);

    $response->assertOk();

    expect($response->json('applied.0.merged'))->toBeTrue();

    $properties = $feature->fresh()->properties;

    expect($properties['status'])->toBe('pending');
    expect($properties['owner'])->toBe('zaki');
});

it('never merges two edits to the same geometry', function () {
    [$map, $layer] = editableMap();

    $feature = Feature::factory()->create(['layer_id' => $layer->id]);

    sendCommands($map, [
        ['op' => 'feature.update', 'id' => $feature->id, 'version' => 1, 'geom' => wkbSquare(103.33, 3.81)],
    ])->assertOk();

    sendCommands($map, [
        ['op' => 'feature.update', 'id' => $feature->id, 'version' => 1, 'geom' => wkbSquare(103.34, 3.82)],
    ], envelope: ['seq' => 2])->assertStatus(409);
});

it('rejects the whole batch when any one command is unauthorised', function () {
    $user = reader();
    [$map, $layer] = editableMap($user);

    $locked = Layer::factory()->create(['owner_map_id' => $map->id, 'locked' => true]);
    MapLayer::factory()->create(['map_id' => $map->id, 'layer_id' => $locked->id, 'sort_key' => 'a1']);

    $response = sendCommands($map, [
        ['op' => 'feature.create', 'tempId' => 'tmp:1', 'layerId' => $layer->id, 'geom' => wkbSquare()],
        ['op' => 'feature.create', 'tempId' => 'tmp:2', 'layerId' => $locked->id, 'geom' => wkbSquare()],
    ], $user);

    $response->assertStatus(403)->assertJsonPath('code', 'command_unauthorized');

    // The authorised command did not slip through on the way to the refusal.
    expect(Feature::count())->toBe(0);
});

it('composes the map role, the placement access and the layer lock', function () {
    $owner = reader();
    $contributor = reader();

    [$map, $layer] = editableMap($owner);
    MapUser::query()->create(['map_id' => $map->id, 'user_id' => $contributor->id, 'role' => 'contributor']);

    // A contributor may draw...
    sendCommands($map, [
        ['op' => 'feature.create', 'tempId' => 'tmp:1', 'layerId' => $layer->id, 'geom' => wkbSquare()],
    ], $contributor)->assertOk();

    // ...but may not restyle, because a style is global to the layer.
    sendCommands($map, [
        ['op' => 'layer.setStyle', 'id' => $layer->id, 'version' => 1, 'style' => ['stroke' => '#f00']],
    ], $contributor, ['seq' => 2])->assertStatus(403);
});

it('refuses to write through a placement shared in as read-only', function () {
    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id]);
    $shared = Layer::factory()->global()->create();

    MapLayer::factory()->readOnly()->create(['map_id' => $map->id, 'layer_id' => $shared->id]);

    // Owning the map does not grant edit on a layer placed in it as `read`.
    sendCommands($map, [
        ['op' => 'feature.create', 'tempId' => 'tmp:1', 'layerId' => $shared->id, 'geom' => wkbSquare()],
    ], $user)->assertStatus(403);
});

it('refuses a layer that is not placed in this map at all', function () {
    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id]);
    $elsewhere = Layer::factory()->create();

    sendCommands($map, [
        ['op' => 'feature.create', 'tempId' => 'tmp:1', 'layerId' => $elsewhere->id, 'geom' => wkbSquare()],
    ], $user)->assertStatus(403);
});

it('refuses a batch above the cap and names the limit', function () {
    [$map, $layer] = editableMap();

    $commands = array_fill(0, (int) config('gis.write.max_batch') + 1, [
        'op' => 'feature.create', 'tempId' => 'tmp', 'layerId' => $layer->id, 'geom' => wkbSquare(),
    ]);

    sendCommands($map, $commands)
        ->assertStatus(422)
        ->assertJsonPath('code', 'batch_too_large')
        ->assertJsonPath('limit', (int) config('gis.write.max_batch'));
});

it('refuses an op that has no handler rather than ignoring it', function () {
    [$map] = editableMap();

    sendCommands($map, [['op' => 'layer.setLocked', 'id' => 1, 'version' => 1, 'locked' => true]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'unknown_op');
});

it('strips markup from property values as they enter', function () {
    // Sanitising on ingest, not at display time: the value reaches an export,
    // a PDF and a template someone writes later, and only the ingest rule has
    // held by then (specification section 20).
    [$map, $layer] = editableMap();

    sendCommands($map, [[
        'op' => 'feature.create',
        'tempId' => 'tmp:1',
        'layerId' => $layer->id,
        'geom' => wkbSquare(),
        'properties' => ['note' => '<script>alert(1)</script>Kuala Kuantan'],
    ]])->assertOk();

    expect(Feature::first()->properties['note'])->toBe('alert(1)Kuala Kuantan');
});

it('refuses a property name that is not a valid identifier', function () {
    [$map, $layer] = editableMap();

    sendCommands($map, [[
        'op' => 'feature.create',
        'tempId' => 'tmp:1',
        'layerId' => $layer->id,
        'geom' => wkbSquare(),
        'properties' => ['no-dashes-allowed' => 'x'],
    ]])->assertStatus(422)->assertJsonPath('code', 'command_invalid');
});

it('writes the derived columns the read path depends on', function () {
    // A feature written without these is invisible to every viewport query,
    // because the read filters on the bbox columns and culls on area.
    [$map, $layer] = editableMap();

    sendCommands($map, [[
        'op' => 'feature.create', 'tempId' => 'tmp:1', 'layerId' => $layer->id,
        'geom' => wkbSquare(103.32, 3.80, 0.001),
    ]])->assertOk();

    $feature = Feature::first();

    expect($feature->minx)->toEqualWithDelta(103.32, 1e-9);
    expect($feature->maxx)->toEqualWithDelta(103.321, 1e-9);
    expect($feature->vertex_count)->toBe(5);

    // ~111 m by ~110.6 m at this latitude, computed geodesically by MySQL.
    expect($feature->area_m2)->toBeGreaterThan(11_000.0);
    expect($feature->area_m2)->toBeLessThan(13_000.0);
});

it('round-trips geometry through base64 WKB without swapping the axes', function () {
    [$map, $layer] = editableMap();

    sendCommands($map, [[
        'op' => 'feature.create', 'tempId' => 'tmp:1', 'layerId' => $layer->id,
        'geom' => wkbSquare(103.32, 3.80),
    ]])->assertOk();

    // Longitude near 103, latitude near 3.8 — not the other way round, which
    // is what a lost `axis-order=long-lat` looks like and raises no error.
    $feature = Feature::first();

    expect($feature->minx)->toBeGreaterThan(100.0);
    expect($feature->miny)->toBeLessThan(10.0);
});

it('lets a later command address a row an earlier one created', function () {
    [$map, $layer] = editableMap();

    $response = sendCommands($map, [
        ['op' => 'feature.create', 'tempId' => 'tmp:1', 'layerId' => $layer->id, 'geom' => wkbSquare()],
        ['op' => 'feature.update', 'id' => 'tmp:1', 'version' => 1, 'properties' => ['status' => 'draft']],
    ]);

    $response->assertOk();

    expect(Feature::first()->properties['status'])->toBe('draft');
});

it('separates layer identity from placement, by id and by version', function () {
    [$map, $layer, $placement] = editableMap();

    // Hiding is a placement command and leaves the layer's version alone.
    sendCommands($map, [['op' => 'layer.setVisible', 'id' => $placement->id, 'version' => 1, 'visible' => false]])
        ->assertOk();

    expect($placement->fresh()->visible)->toBeFalse();
    expect($placement->fresh()->version)->toBe(2);
    expect($layer->fresh()->version)->toBe(1);

    // Sending the placement id where the layer id is meant is a conflict, not
    // a silent write to the wrong row.
    sendCommands($map, [['op' => 'layer.rename', 'id' => $placement->id, 'version' => 1, 'name' => 'x']], envelope: ['seq' => 2])
        ->assertStatus(403);
});

it('reorders a placement by writing one row', function () {
    [$map, $layer, $placement] = editableMap();

    $group = MapLayer::factory()->create(['map_id' => $map->id, 'sort_key' => 'a1']);

    sendCommands($map, [[
        'op' => 'layer.reorder', 'id' => $placement->id, 'version' => 1,
        'parentId' => $group->id, 'sortKey' => 'a0V',
    ]])->assertOk();

    expect($placement->fresh()->parent_id)->toBe($group->id);
    expect($placement->fresh()->sort_key)->toBe('a0V');
    expect($group->fresh()->version)->toBe(1);
});

it('creates and deletes a measurement, which belongs to the map', function () {
    [$map] = editableMap();

    sendCommands($map, [[
        'op' => 'measurement.create', 'tempId' => 'tmp:m', 'kind' => 'distance',
        'geom' => wkbSquare(), 'value' => 412.5, 'unit' => 'm',
    ]])->assertOk();

    $measurement = Measurement::first();

    expect($measurement->map_id)->toBe($map->id);
    expect($measurement->value)->toBe(412.5);

    sendCommands($map, [['op' => 'measurement.delete', 'id' => $measurement->id, 'version' => 1]], envelope: ['seq' => 2])
        ->assertOk();

    expect(Measurement::count())->toBe(0);
});

it('refuses a map the acting user is not a member of', function () {
    $stranger = reader();
    [$map, $layer] = editableMap();

    sendCommands($map, [
        ['op' => 'feature.create', 'tempId' => 'tmp:1', 'layerId' => $layer->id, 'geom' => wkbSquare()],
    ], $stranger)->assertStatus(403)->assertJsonPath('code', 'command_unauthorized');
});
