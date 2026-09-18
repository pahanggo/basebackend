<?php

use App\Models\User;
use Gis\Models\Feature;
use Gis\Models\Layer;
use Gis\Models\Map;
use Gis\Models\MapLayer;
use Gis\Models\MapUser;
use Gis\Testing\RefreshesGisDatabase;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Helpers.php';

uses(RefreshesGisDatabase::class);

function listMaps(User $user, array $query = []): Illuminate\Testing\TestResponse
{
    return test()->actingAs($user)->getJson(route('gis.api.maps.index', $query));
}

it('lists only maps the user may open', function () {
    $user = reader();
    $stranger = reader();

    $own = Map::factory()->create(['owner_id' => $user->id, 'name' => 'Mine']);
    $shared = Map::factory()->create(['owner_id' => $stranger->id, 'name' => 'Shared with me']);
    Map::factory()->create(['owner_id' => $stranger->id, 'name' => 'Not mine']);

    MapUser::query()->create(['map_id' => $shared->id, 'user_id' => $user->id, 'role' => 'viewer']);

    $response = listMaps($user);

    $names = collect($response->json('data'))->pluck('name')->sort()->values()->all();

    expect($names)->toBe(['Mine', 'Shared with me']);
    expect($response->json('meta.total'))->toBe(2);
});

it('carries the acting user\'s role on each row', function () {
    // So the client knows which actions to offer without a second request.
    $user = reader();
    $other = reader();

    Map::factory()->create(['owner_id' => $user->id, 'name' => 'Mine']);
    $shared = Map::factory()->create(['owner_id' => $other->id, 'name' => 'Theirs']);
    MapUser::query()->create(['map_id' => $shared->id, 'user_id' => $user->id, 'role' => 'contributor']);

    $roles = collect(listMaps($user)->json('data'))->pluck('role', 'name');

    expect($roles['Mine'])->toBe('owner');
    expect($roles['Theirs'])->toBe('contributor');
});

it('counts only the features of layers this map owns', function () {
    // Summing shared layers would print the 1.4 million cadastral base on every
    // row and tell the user nothing about the work done in that map.
    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id]);

    Layer::factory()->create(['owner_map_id' => $map->id, 'feature_count' => 12]);

    $base = Layer::factory()->global()->create(['feature_count' => 672109]);
    MapLayer::factory()->readOnly()->create(['map_id' => $map->id, 'layer_id' => $base->id]);

    $row = listMaps($user)->json('data.0');

    expect($row['featureCount'])->toBe(12);
    expect($row['layerCount'])->toBe(1);
});

it('searches and pages server-side', function () {
    $user = reader();

    Map::factory()->create(['owner_id' => $user->id, 'name' => 'Site survey']);
    Map::factory()->create(['owner_id' => $user->id, 'name' => 'Drainage plan']);

    $response = listMaps($user, ['q' => 'survey']);

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.name'))->toBe('Site survey');
});

it('creates a map that opens straight from the creation response', function () {
    $user = reader();

    $response = test()->actingAs($user)->postJson(route('gis.api.maps.store'), [
        'name' => 'Site survey',
        'clientId' => 'a3f9c2',
        'seq' => 1,
    ]);

    $response->assertCreated()
        ->assertJsonPath('name', 'Site survey')
        ->assertJsonPath('role', 'owner')
        ->assertJsonPath('version', 1);

    // The same shape as the bootstrap read, so no follow-up GET is needed.
    expect($response->json('layers'))->toBe([]);
    expect($response->json('capabilities.maxBatch'))->toBe((int) config('gis.write.max_batch'));
    expect($response->json('basemaps.basemaps'))->not->toBeEmpty();
});

it('creates the same map once however many times the request is retried', function () {
    // A duplicate map is a worse failure than a duplicate feature: the user may
    // not notice until both have diverged.
    $user = reader();

    $payload = ['name' => 'Site survey', 'clientId' => 'a3f9c2', 'seq' => 1];

    $first = test()->actingAs($user)->postJson(route('gis.api.maps.store'), $payload);
    $second = test()->actingAs($user)->postJson(route('gis.api.maps.store'), $payload);

    $first->assertCreated();
    $second->assertOk();

    expect(Map::count())->toBe(1);
    expect($second->json('replayed'))->toBeTrue();
});

it('copies a map by sharing its layers, never its features', function () {
    $user = reader();
    $source = Map::factory()->create(['owner_id' => $user->id]);

    $own = Layer::factory()->create(['owner_map_id' => $source->id, 'feature_count' => 3]);
    $base = Layer::factory()->global()->create(['feature_count' => 672109]);

    MapLayer::factory()->create(['map_id' => $source->id, 'layer_id' => $own->id, 'sort_key' => 'a0']);
    MapLayer::factory()->readOnly()->create(['map_id' => $source->id, 'layer_id' => $base->id, 'sort_key' => 'a1']);

    Feature::factory()->count(3)->create(['layer_id' => $own->id]);

    $started = microtime(true);

    $response = test()->actingAs($user)->postJson(route('gis.api.maps.store'), [
        'name' => 'Copy',
        'clientId' => 'a3f9c2',
        'seq' => 1,
        'from' => ['kind' => 'copy', 'mapId' => $source->id],
    ]);

    $elapsed = (microtime(true) - $started) * 1000;

    $response->assertCreated();

    // Two placements written, no layers and no features duplicated — which is
    // what makes copying a statewide map instant rather than impossible.
    expect(Layer::count())->toBe(2);
    expect(Feature::count())->toBe(3);
    expect(MapLayer::count())->toBe(4);
    expect(count($response->json('layers')))->toBe(2);
    expect($elapsed)->toBeLessThan(1000.0);
});

it('copies placements without granting ownership of the layers', function () {
    // The copy may edit what the source owned, but cannot delete it: the layer
    // still belongs to the source map, and ownership is what grants delete.
    $user = reader();
    $source = Map::factory()->create(['owner_id' => $user->id]);
    $layer = Layer::factory()->create(['owner_map_id' => $source->id]);

    MapLayer::factory()->create(['map_id' => $source->id, 'layer_id' => $layer->id]);

    $response = test()->actingAs($user)->postJson(route('gis.api.maps.store'), [
        'name' => 'Copy', 'clientId' => 'a3f9c2', 'seq' => 1,
        'from' => ['kind' => 'copy', 'mapId' => $source->id],
    ]);

    expect($response->json('layers.0.access'))->toBe('edit');
    expect($response->json('layers.0.ownedHere'))->toBeFalse();
});

it('preserves the tree when copying a map with groups', function () {
    $user = reader();
    $source = Map::factory()->create(['owner_id' => $user->id]);

    $groupLayer = Layer::factory()->create(['owner_map_id' => $source->id, 'kind' => 'group']);
    $childLayer = Layer::factory()->create(['owner_map_id' => $source->id]);

    $group = MapLayer::factory()->create(['map_id' => $source->id, 'layer_id' => $groupLayer->id, 'sort_key' => 'a0']);
    MapLayer::factory()->create([
        'map_id' => $source->id, 'layer_id' => $childLayer->id,
        'parent_id' => $group->id, 'sort_key' => 'a0V',
    ]);

    $response = test()->actingAs($user)->postJson(route('gis.api.maps.store'), [
        'name' => 'Copy', 'clientId' => 'a3f9c2', 'seq' => 1,
        'from' => ['kind' => 'copy', 'mapId' => $source->id],
    ]);

    $layers = collect($response->json('layers'));
    $newGroup = $layers->firstWhere('kind', 'group');
    $newChild = $layers->firstWhere('kind', 'vector');

    // The parent points at the placement in the NEW map, not the old one.
    expect($newChild['parentId'])->toBe($newGroup['placementId']);
    expect($newChild['parentId'])->not->toBe($group->id);
});

it('refuses to copy a map the user may not open', function () {
    $user = reader();
    $source = Map::factory()->create(['owner_id' => reader()->id]);

    test()->actingAs($user)->postJson(route('gis.api.maps.store'), [
        'name' => 'Copy', 'clientId' => 'a3f9c2', 'seq' => 1,
        'from' => ['kind' => 'copy', 'mapId' => $source->id],
    ])->assertStatus(403);
});

it('refuses the bootstrap of a map the user is not a member of', function () {
    $map = Map::factory()->create(['owner_id' => reader()->id]);

    test()->actingAs(reader())
        ->getJson(route('gis.api.maps.show', ['map' => $map->id]))
        ->assertStatus(403)
        ->assertJsonPath('code', 'command_unauthorized');
});

it('renames a map on its version and conflicts otherwise', function () {
    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id, 'name' => 'Old']);

    test()->actingAs($user)
        ->patchJson(route('gis.api.maps.update', ['map' => $map->id]), ['version' => 1, 'name' => 'New'])
        ->assertOk();

    expect($map->fresh()->name)->toBe('New');

    test()->actingAs($user)
        ->patchJson(route('gis.api.maps.update', ['map' => $map->id]), ['version' => 1, 'name' => 'Newer'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'version_conflict');

    expect($map->fresh()->name)->toBe('New');
});

it('soft deletes for the owner only, and refuses the open map', function () {
    $user = reader();
    $editor = reader();
    $map = Map::factory()->create(['owner_id' => $user->id]);

    MapUser::query()->create(['map_id' => $map->id, 'user_id' => $editor->id, 'role' => 'editor']);

    // An editor may change the map but not delete it.
    test()->actingAs($editor)
        ->deleteJson(route('gis.api.maps.destroy', ['map' => $map->id]))
        ->assertStatus(403);

    // Deleting the map you are looking at leaves the editor holding nothing.
    test()->actingAs($user)
        ->deleteJson(route('gis.api.maps.destroy', ['map' => $map->id, 'openMapId' => $map->id]))
        ->assertStatus(409)
        ->assertJsonPath('code', 'map_open');

    test()->actingAs($user)
        ->deleteJson(route('gis.api.maps.destroy', ['map' => $map->id]))
        ->assertOk();

    expect(Map::count())->toBe(0);
    expect(Map::withTrashed()->count())->toBe(1);
});

it('lists deleted maps to their owner and restores them for administrators only', function () {
    $owner = reader();
    $admin = gisAdministrator();
    $map = Map::factory()->create(['owner_id' => $owner->id, 'name' => 'Deleted']);

    $map->delete();

    expect(listMaps($owner)->json('meta.total'))->toBe(0);
    expect(listMaps($owner, ['deleted' => 1])->json('meta.total'))->toBe(1);

    // The owner may delete and may not undo it themselves.
    $live = Map::factory()->create(['owner_id' => $owner->id]);

    sendCommands($live, [['op' => 'map.restore', 'id' => $map->id]], $owner)
        ->assertStatus(403)
        ->assertJsonPath('code', 'command_unauthorized');

    MapUser::query()->create(['map_id' => $live->id, 'user_id' => $admin->id, 'role' => 'editor']);

    sendCommands($live, [['op' => 'map.restore', 'id' => $map->id]], $admin, ['seq' => 2])->assertOk();

    expect(Map::count())->toBe(2);
});

it('takes the basemap list from the tile service and classifies weather as overlays', function () {
    Http::fake([
        '*' => Http::response(['providers' => ['grayscale', 'google-roadmap', 'owm-clouds', 'owm-precipitation']]),
    ]);

    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id]);

    $response = test()->actingAs($user)->getJson(route('gis.api.maps.show', ['map' => $map->id]));

    // Classified by prefix, so a sixth `owm-` provider needs no code change.
    expect($response->json('basemaps.basemaps'))->toBe(['grayscale', 'google-roadmap']);
    expect($response->json('basemaps.overlays'))->toBe(['owm-clouds', 'owm-precipitation']);
    expect($response->json('basemaps.source'))->toBe('service');

    // The tile template comes from the application's own config, not from here.
    expect($response->json('basemaps.urlTemplate'))->toBe(config('services.map_tiles.url'));
});

it('falls back to the cache and then to the default when the tile service is down', function () {
    // A basemap the user cannot change beats a map that will not load.
    //
    // One fake with a switch rather than two calls to `Http::fake`: the second
    // call merges its stubs behind the first rather than replacing them, so the
    // service would never appear to fail and the test would pass without
    // exercising anything.
    // An object rather than a captured variable: an arrow function closes over
    // its variables by VALUE, so flipping a bool afterwards would never reach
    // the stub and the service would never appear to fail.
    $service = new stdClass;
    $service->up = true;

    Http::fake(fn () => $service->up
        ? Http::response(['providers' => ['grayscale', 'street']])
        : Http::response(status: 503));

    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id]);

    test()->actingAs($user)->getJson(route('gis.api.maps.show', ['map' => $map->id]))->assertOk();

    $service->up = false;

    $cached = test()->actingAs($user)->getJson(route('gis.api.maps.show', ['map' => $map->id]));

    expect($cached->json('basemaps.basemaps'))->toBe(['grayscale', 'street']);
    expect($cached->json('basemaps.source'))->toBe('cache');

    cache()->forget(config('gis.basemaps.cache_key'));

    $bare = test()->actingAs($user)->getJson(route('gis.api.maps.show', ['map' => $map->id]));

    expect($bare->json('basemaps.basemaps'))->toBe([config('gis.basemaps.default')]);
    expect($bare->json('basemaps.source'))->toBe('default');
});

it('survives the tile service throwing rather than answering', function () {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('unreachable'));

    cache()->forget(config('gis.basemaps.cache_key'));

    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id]);

    test()->actingAs($user)
        ->getJson(route('gis.api.maps.show', ['map' => $map->id]))
        ->assertOk()
        ->assertJsonPath('basemaps.source', 'default');
});

it('flattens layer and placement into one entry with two ids and two versions', function () {
    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id]);
    $layer = Layer::factory()->create(['owner_map_id' => $map->id, 'version' => 4]);
    $placement = MapLayer::factory()->create(['map_id' => $map->id, 'layer_id' => $layer->id, 'version' => 2]);

    $entry = test()->actingAs($user)
        ->getJson(route('gis.api.maps.show', ['map' => $map->id]))
        ->json('layers.0');

    expect($entry['layerId'])->toBe($layer->id);
    expect($entry['placementId'])->toBe($placement->id);
    expect($entry['layerVersion'])->toBe(4);
    expect($entry['placementVersion'])->toBe(2);
    expect($entry['shared'])->toBeFalse();

    // No geometry anywhere in the bootstrap, at any size.
    expect($entry)->not->toHaveKey('geom');
});

it('marks a layer as shared when it is placed in more than one map', function () {
    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id]);
    $base = Layer::factory()->global()->create();

    MapLayer::factory()->readOnly()->create(['map_id' => $map->id, 'layer_id' => $base->id]);
    MapLayer::factory()->readOnly()->create(['map_id' => Map::factory(), 'layer_id' => $base->id]);

    $entry = test()->actingAs($user)
        ->getJson(route('gis.api.maps.show', ['map' => $map->id]))
        ->json('layers.0');

    expect($entry['shared'])->toBeTrue();
    expect($entry['ownedHere'])->toBeFalse();
    expect($entry['access'])->toBe('read');
});

it('remembers where the map is looking without touching its version', function () {
    // The map version is the replay sequence for its command log, so a pan must
    // not bump it: that would thread holes through the sequence and invalidate
    // the version every other client holds, many times a minute.
    [$map] = editableMap();
    $before = $map->version;

    test()->actingAs(User::query()->findOrFail($map->owner_id))
        ->putJson(route('gis.api.maps.view', ['map' => $map->id]), [
            'center' => [103.4, 3.9],
            'zoom' => 16,
        ])
        ->assertNoContent();

    $map->refresh();

    expect($map->version)->toBe($before);
    expect($map->view_state['center'])->toBe([103.4, 3.9]);
    expect($map->view_state['zoom'])->toBe(16);
});

it('keeps the basemap when a client sends only where it is looking', function () {
    [$map] = editableMap();
    $map->forceFill(['view_state' => ['center' => [103.3, 3.8], 'zoom' => 12, 'basemap' => 'google-satellite']])->saveQuietly();

    test()->actingAs(User::query()->findOrFail($map->owner_id))
        ->putJson(route('gis.api.maps.view', ['map' => $map->id]), ['center' => [103.4, 3.9], 'zoom' => 16])
        ->assertNoContent();

    expect($map->fresh()->view_state['basemap'])->toBe('google-satellite');
});

it('will not let a viewer move where the map opens for everyone', function () {
    [$map] = editableMap();
    $stranger = reader();

    MapUser::query()->create(['map_id' => $map->id, 'user_id' => $stranger->id, 'role' => 'viewer']);

    test()->actingAs($stranger)
        ->putJson(route('gis.api.maps.view', ['map' => $map->id]), ['center' => [103.4, 3.9], 'zoom' => 16])
        ->assertStatus(403);
});

it('refuses a view that is not on the planet', function () {
    [$map] = editableMap();
    $owner = User::query()->findOrFail($map->owner_id);

    test()->actingAs($owner)
        ->putJson(route('gis.api.maps.view', ['map' => $map->id]), ['center' => [200.0, 3.9], 'zoom' => 16])
        ->assertStatus(422);

    test()->actingAs($owner)
        ->putJson(route('gis.api.maps.view', ['map' => $map->id]), ['center' => [103.4, 3.9], 'zoom' => 40])
        ->assertStatus(422);
});
