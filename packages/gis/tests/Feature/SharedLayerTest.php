<?php

use App\Models\User;
use Gis\Models\Feature;
use Gis\Models\Layer;
use Gis\Models\Map;
use Gis\Models\MapLayer;
use Gis\Models\MapUser;
use Gis\Testing\RefreshesGisDatabase;

uses(RefreshesGisDatabase::class);

function library(User $user, array $query = []): Illuminate\Testing\TestResponse
{
    return test()->actingAs($user)->getJson(route('gis.api.layers.index', $query));
}

it('lists global layers and the user\'s own, and nothing else', function () {
    // The library must not become a discovery channel for maps the user
    // cannot open.
    $user = reader();
    $stranger = reader();

    $mine = Map::factory()->create(['owner_id' => $user->id]);

    Layer::factory()->global()->create(['name' => 'Lot']);
    Layer::factory()->create(['owner_map_id' => $mine->id, 'name' => 'My drawing']);
    Layer::factory()->create(['owner_map_id' => Map::factory()->create(['owner_id' => $stranger->id])->id, 'name' => 'Secret']);

    $names = collect(library($user)->json('data'))->pluck('name')->sort()->values()->all();

    expect($names)->toBe(['Lot', 'My drawing']);
});

it('offers edit only where the user already holds edit rights', function () {
    // Without this the permission model leaks: a user with their own map could
    // add the cadastral base as editable and rewrite 1.4 million features.
    $user = reader();
    $mine = Map::factory()->create(['owner_id' => $user->id]);

    $base = Layer::factory()->global()->create(['name' => 'Lot']);
    $own = Layer::factory()->create(['owner_map_id' => $mine->id, 'name' => 'My drawing']);

    MapLayer::factory()->readOnly()->create(['map_id' => $mine->id, 'layer_id' => $base->id, 'sort_key' => 'a0']);
    MapLayer::factory()->create(['map_id' => $mine->id, 'layer_id' => $own->id, 'sort_key' => 'a1']);

    $access = collect(library($user)->json('data'))->pluck('availableAccess', 'name');

    expect($access['Lot'])->toBe(['read']);
    expect($access['My drawing'])->toBe(['read', 'edit']);
});

it('marks layers already placed rather than hiding them', function () {
    // So a user searching for something they already added sees why it is not
    // selectable.
    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id]);
    $base = Layer::factory()->global()->create(['name' => 'Lot']);

    MapLayer::factory()->readOnly()->create(['map_id' => $map->id, 'layer_id' => $base->id]);

    $row = library($user, ['excludePlacedIn' => $map->id])->json('data.0');

    expect($row['placedInThisMap'])->toBeTrue();
    expect($row['featureCount'])->toBe($base->feature_count);
});

it('shows the owning map so global layers read as base data', function () {
    $user = reader();
    $mine = Map::factory()->create(['owner_id' => $user->id, 'name' => 'Site survey']);

    Layer::factory()->global()->create(['name' => 'Lot']);
    Layer::factory()->create(['owner_map_id' => $mine->id, 'name' => 'Drawing']);

    $rows = collect(library($user)->json('data'))->keyBy('name');

    expect($rows['Lot']['ownerMapId'])->toBeNull();
    expect($rows['Lot']['ownerMapName'])->toBeNull();
    expect($rows['Drawing']['ownerMapName'])->toBe('Site survey');
});

it('places a layer by writing one row and copying nothing', function () {
    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id]);
    $base = Layer::factory()->global()->create(['feature_count' => 3]);

    Feature::factory()->count(3)->create(['layer_id' => $base->id]);

    sendCommands($map, [[
        'op' => 'layer.share', 'layerId' => $base->id, 'mapId' => $map->id,
        'access' => 'read', 'sortKey' => 'a0', 'tempId' => 'tmp:1',
    ]], $user)->assertOk();

    expect(MapLayer::count())->toBe(1);
    expect(Layer::count())->toBe(1);
    expect(Feature::count())->toBe(3);
});

it('refuses layer.share with edit when the user holds only read rights', function () {
    // Called directly, bypassing the modal: a disabled control in a dialog is
    // not an authorization boundary.
    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id]);
    $base = Layer::factory()->global()->create();

    sendCommands($map, [[
        'op' => 'layer.share', 'layerId' => $base->id, 'mapId' => $map->id,
        'access' => 'edit', 'sortKey' => 'a0',
    ]], $user)->assertStatus(403)->assertJsonPath('code', 'command_unauthorized');

    expect(MapLayer::count())->toBe(0);
});

it('refuses sharing a layer the user cannot even see', function () {
    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id]);
    $secret = Layer::factory()->create(['owner_map_id' => Map::factory()->create(['owner_id' => reader()->id])->id]);

    sendCommands($map, [[
        'op' => 'layer.share', 'layerId' => $secret->id, 'mapId' => $map->id,
        'access' => 'read', 'sortKey' => 'a0',
    ]], $user)->assertStatus(403);
});

it('refuses placing the same layer twice, by the constraint and not the UI', function () {
    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id]);
    $base = Layer::factory()->global()->create();

    MapLayer::factory()->readOnly()->create(['map_id' => $map->id, 'layer_id' => $base->id]);

    sendCommands($map, [[
        'op' => 'layer.share', 'layerId' => $base->id, 'mapId' => $map->id,
        'access' => 'read', 'sortKey' => 'a1',
    ]], $user)->assertStatus(422)->assertJsonPath('code', 'command_invalid');

    expect(MapLayer::count())->toBe(1);
});

it('gives one layer independent position, visibility and opacity per map', function () {
    $user = reader();
    $first = Map::factory()->create(['owner_id' => $user->id]);
    $second = Map::factory()->create(['owner_id' => $user->id]);
    $base = Layer::factory()->global()->create();

    $here = MapLayer::factory()->create(['map_id' => $first->id, 'layer_id' => $base->id, 'sort_key' => 'a0']);
    $there = MapLayer::factory()->create(['map_id' => $second->id, 'layer_id' => $base->id, 'sort_key' => 'z9']);

    sendCommands($first, [
        ['op' => 'layer.setVisible', 'id' => $here->id, 'version' => 1, 'visible' => false],
        ['op' => 'layer.setOpacity', 'id' => $here->id, 'version' => 2, 'opacity' => 0.4],
    ], $user)->assertOk();

    expect($here->fresh()->visible)->toBeFalse();
    expect($here->fresh()->opacity)->toBe(0.4);

    // The other map is untouched: the placement is where per-map state lives.
    expect($there->fresh()->visible)->toBeTrue();
    expect($there->fresh()->opacity)->toBe(1.0);
    expect($there->fresh()->sort_key)->toBe('z9');
});

it('refuses every mutating command through a read placement, even to the map owner', function () {
    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id]);
    $base = Layer::factory()->global()->create();

    $placement = MapLayer::factory()->readOnly()->create(['map_id' => $map->id, 'layer_id' => $base->id]);

    $refused = [
        ['op' => 'feature.create', 'tempId' => 't', 'layerId' => $base->id, 'geom' => wkbSquare()],
        ['op' => 'layer.rename', 'id' => $base->id, 'version' => 1, 'name' => 'Renamed'],
        ['op' => 'layer.setStyle', 'id' => $base->id, 'version' => 1, 'style' => ['stroke' => '#f00']],
        ['op' => 'layer.delete', 'id' => $base->id, 'version' => 1],
        ['op' => 'layer.setLocked', 'id' => $base->id, 'version' => 1, 'locked' => true],
    ];

    foreach ($refused as $i => $command) {
        sendCommands($map, [$command], $user, ['seq' => $i + 1])
            ->assertStatus(403, "{$command['op']} should be refused through a read placement");
    }

    // Visibility and opacity are NOT refused: they are this map's view of the
    // layer, not a change to the layer.
    sendCommands($map, [['op' => 'layer.setVisible', 'id' => $placement->id, 'version' => 1, 'visible' => false]], $user, ['seq' => 99])
        ->assertOk();
});

it('refuses layer.setAccess from a map that does not own the layer', function () {
    // There is no self-escalation path: holding a read placement does not let
    // you promote it.
    $user = reader();
    $owningMap = Map::factory()->create(['owner_id' => reader()->id]);
    $myMap = Map::factory()->create(['owner_id' => $user->id]);

    $layer = Layer::factory()->create(['owner_map_id' => $owningMap->id]);
    $mine = MapLayer::factory()->readOnly()->create(['map_id' => $myMap->id, 'layer_id' => $layer->id]);

    sendCommands($myMap, [['op' => 'layer.setAccess', 'id' => $mine->id, 'version' => 1, 'access' => 'edit']], $user)
        ->assertStatus(403);

    expect($mine->fresh()->access)->toBe('read');
});

it('lets the owning map hand out edit on its own layer', function () {
    $owner = reader();
    $guest = reader();

    $owningMap = Map::factory()->create(['owner_id' => $owner->id]);
    $otherMap = Map::factory()->create(['owner_id' => $guest->id]);

    $layer = Layer::factory()->create(['owner_map_id' => $owningMap->id]);

    MapLayer::factory()->create(['map_id' => $owningMap->id, 'layer_id' => $layer->id]);
    $theirs = MapLayer::factory()->readOnly()->create(['map_id' => $otherMap->id, 'layer_id' => $layer->id]);

    sendCommands($owningMap, [['op' => 'layer.setAccess', 'id' => $theirs->id, 'version' => 1, 'access' => 'edit']], $owner)
        ->assertOk();

    expect($theirs->fresh()->access)->toBe('edit');
});

it('removes a placement without touching the layer, and deletes it everywhere from the owner', function () {
    $user = reader();
    $owningMap = Map::factory()->create(['owner_id' => $user->id]);
    $otherMap = Map::factory()->create(['owner_id' => $user->id]);

    $layer = Layer::factory()->create(['owner_map_id' => $owningMap->id]);

    $here = MapLayer::factory()->create(['map_id' => $owningMap->id, 'layer_id' => $layer->id]);
    $there = MapLayer::factory()->create(['map_id' => $otherMap->id, 'layer_id' => $layer->id]);

    // Remove from the other map: the layer survives.
    sendCommands($otherMap, [['op' => 'layer.removeFromMap', 'id' => $there->id, 'version' => 1]], $user)->assertOk();

    expect(MapLayer::count())->toBe(1);
    expect(Layer::count())->toBe(1);

    // Delete from the owning map: the layer goes, everywhere.
    sendCommands($owningMap, [['op' => 'layer.delete', 'id' => $layer->id, 'version' => 1]], $user, ['seq' => 2])->assertOk();

    expect(Layer::count())->toBe(0);
    expect(Layer::withTrashed()->count())->toBe(1);
});

it('refuses deleting a layer from a map that only holds a placement of it', function () {
    $user = reader();
    $owningMap = Map::factory()->create(['owner_id' => reader()->id]);
    $myMap = Map::factory()->create(['owner_id' => $user->id]);

    $layer = Layer::factory()->create(['owner_map_id' => $owningMap->id]);
    MapLayer::factory()->create(['map_id' => $myMap->id, 'layer_id' => $layer->id, 'access' => 'edit']);

    sendCommands($myMap, [['op' => 'layer.delete', 'id' => $layer->id, 'version' => 1]], $user)->assertStatus(403);

    expect(Layer::count())->toBe(1);
});

it('restores a deleted layer for administrators only', function () {
    $owner = reader();
    $admin = gisAdministrator();

    $map = Map::factory()->create(['owner_id' => $owner->id]);
    $layer = Layer::factory()->create(['owner_map_id' => $map->id]);
    MapLayer::factory()->create(['map_id' => $map->id, 'layer_id' => $layer->id]);

    sendCommands($map, [['op' => 'layer.delete', 'id' => $layer->id, 'version' => 1]], $owner)->assertOk();

    sendCommands($map, [['op' => 'layer.restore', 'id' => $layer->id]], $owner, ['seq' => 2])->assertStatus(403);

    MapUser::query()->create(['map_id' => $map->id, 'user_id' => $admin->id, 'role' => 'editor']);

    sendCommands($map, [['op' => 'layer.restore', 'id' => $layer->id]], $admin, ['seq' => 3])->assertOk();

    // Restoring brings it back into every map that had it, which is what a
    // restore has to mean when the thing restored is shared.
    expect(Layer::count())->toBe(1);
    expect(MapLayer::count())->toBe(1);
});

it('locks a layer against editing everywhere, including for its owner', function () {
    $user = reader();
    [$map, $layer] = editableMap($user);

    sendCommands($map, [['op' => 'layer.setLocked', 'id' => $layer->id, 'version' => 1, 'locked' => true]], $user)->assertOk();

    sendCommands($map, [['op' => 'feature.create', 'tempId' => 't', 'layerId' => $layer->id, 'geom' => wkbSquare()]], $user, ['seq' => 2])
        ->assertStatus(403);

    // Unlocking is still possible, or the lock would be permanent.
    sendCommands($map, [['op' => 'layer.setLocked', 'id' => $layer->id, 'version' => 2, 'locked' => false]], $user, ['seq' => 3])
        ->assertOk();
});
