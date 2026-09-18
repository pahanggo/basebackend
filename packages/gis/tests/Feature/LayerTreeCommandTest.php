<?php

use Gis\Models\Layer;
use Gis\Models\MapLayer;
use Gis\Support\SortKey;
use Gis\Testing\RefreshesGisDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshesGisDatabase::class);

it('creates a layer and its placement together, returning both ids', function () {
    // A layer with no placement is invisible everywhere and a placement needs
    // something to point at, so they are always born as a pair.
    [$map] = editableMap();

    $response = sendCommands($map, [[
        'op' => 'layer.create', 'tempId' => 'tmp:1', 'kind' => 'vector',
        'name' => 'Drainage', 'sortKey' => 'a1',
    ]]);

    $response->assertOk();

    $applied = collect($response->json('applied'));

    expect($applied->firstWhere('entity', 'layer')['tempId'])->toBe('tmp:1');
    expect($applied->firstWhere('entity', 'placement')['tempId'])->toBe('tmp:1:placement');

    $layer = Layer::query()->where('name', 'Drainage')->first();

    expect($layer->owner_map_id)->toBe($map->id);
    expect($layer->placements()->first()->access)->toBe('owner');
});

it('refuses a layer kind that is not in the enum', function () {
    [$map] = editableMap();

    sendCommands($map, [[
        'op' => 'layer.create', 'tempId' => 't', 'kind' => 'pointcloud',
        'name' => 'x', 'sortKey' => 'a0',
    ]])->assertStatus(422)->assertJsonPath('code', 'command_invalid');
});

it('writes exactly one row when a node is reordered', function () {
    // The whole reason the sort key is fractional: dragging in a deep tree must
    // not renumber siblings.
    [$map, $layer, $placement] = editableMap();

    $siblings = collect(['a1', 'a2', 'a3'])->map(fn (string $key) => MapLayer::factory()->create([
        'map_id' => $map->id, 'sort_key' => $key,
    ]));

    $before = MapLayer::query()->pluck('sort_key', 'id');

    $queries = 0;
    DB::connection(config('gis.connection'))->listen(function ($query) use (&$queries) {
        if (str_starts_with(strtolower(trim($query->sql)), 'update `gis_map_layer`')) {
            $queries++;
        }
    });

    sendCommands($map, [[
        'op' => 'layer.reorder', 'id' => $placement->id, 'version' => 1,
        'parentId' => null, 'sortKey' => SortKey::between('a1', 'a2'),
    ]])->assertOk();

    expect($queries)->toBe(1);

    // Every sibling still carries the key it had.
    foreach ($siblings as $sibling) {
        expect($sibling->fresh()->sort_key)->toBe($before[$sibling->id]);
    }
});

it('refuses a sort key that is not a base-62 fractional index', function () {
    [$map, , $placement] = editableMap();

    sendCommands($map, [[
        'op' => 'layer.reorder', 'id' => $placement->id, 'version' => 1,
        'parentId' => null, 'sortKey' => 'a0; drop table',
    ]])->assertStatus(422);
});

it('groups nodes and dissolves the group back', function () {
    [$map, , $first] = editableMap();
    $second = MapLayer::factory()->create(['map_id' => $map->id, 'sort_key' => 'a1']);

    $response = sendCommands($map, [[
        'op' => 'layer.group', 'tempId' => 'tmp:g', 'name' => 'Base data',
        'placementIds' => [$first->id, $second->id], 'sortKey' => 'a0',
    ]]);

    $response->assertOk();

    $groupId = collect($response->json('applied'))->firstWhere('tempId', 'tmp:g:placement')['id'];

    expect($first->fresh()->parent_id)->toBe($groupId);
    expect($second->fresh()->parent_id)->toBe($groupId);

    sendCommands($map, [['op' => 'layer.ungroup', 'id' => $groupId, 'version' => 1]], envelope: ['seq' => 2])
        ->assertOk();

    // Children are reparented, not orphaned: a placement whose parent is gone
    // disappears from the tree without being deleted.
    expect($first->fresh()->parent_id)->toBeNull();
    expect($second->fresh()->parent_id)->toBeNull();

    // The group's own layer goes with it — an empty group is a node with no
    // meaning the user then has to remove by hand.
    expect(MapLayer::query()->whereKey($groupId)->exists())->toBeFalse();
    expect(Layer::query()->where('kind', 'group')->exists())->toBeFalse();
});

it('refuses ungrouping something that is not a group', function () {
    [$map, , $placement] = editableMap();

    sendCommands($map, [['op' => 'layer.ungroup', 'id' => $placement->id, 'version' => 1]])
        ->assertStatus(422);
});

it('reparents children when a placement is removed from the map', function () {
    [$map, , $child] = editableMap();

    $groupLayer = Layer::factory()->create(['owner_map_id' => $map->id, 'kind' => 'group']);
    $group = MapLayer::factory()->create(['map_id' => $map->id, 'layer_id' => $groupLayer->id, 'sort_key' => 'a0']);

    $child->update(['parent_id' => $group->id]);

    sendCommands($map, [['op' => 'layer.removeFromMap', 'id' => $group->id, 'version' => 1]])->assertOk();

    expect($child->fresh()->parent_id)->toBeNull();
    expect(MapLayer::query()->whereKey($child->id)->exists())->toBeTrue();
});

it('sets a zoom range and refuses an inverted one', function () {
    [$map, , $placement] = editableMap();

    sendCommands($map, [[
        'op' => 'layer.setZoomRange', 'id' => $placement->id, 'version' => 1,
        'minZoom' => 12, 'maxZoom' => 18,
    ]])->assertOk();

    expect($placement->fresh()->min_zoom)->toBe(12);
    expect($placement->fresh()->max_zoom)->toBe(18);

    sendCommands($map, [[
        'op' => 'layer.setZoomRange', 'id' => $placement->id, 'version' => 2,
        'minZoom' => 18, 'maxZoom' => 12,
    ]], envelope: ['seq' => 2])->assertStatus(422);
});

it('refuses an opacity outside 0 to 1', function () {
    [$map, , $placement] = editableMap();

    sendCommands($map, [['op' => 'layer.setOpacity', 'id' => $placement->id, 'version' => 1, 'opacity' => 1.5]])
        ->assertStatus(422);
});

it('keeps fractional keys ordered however often a node is dropped between two', function () {
    // The property the whole scheme rests on: there is always room between any
    // two keys, without renumbering.
    $left = 'a0';
    $right = 'a1';

    for ($i = 0; $i < 40; $i++) {
        $middle = SortKey::between($left, $right);

        expect(strcmp($left, $middle))->toBeLessThan(0);
        expect(strcmp($middle, $right))->toBeLessThan(0);

        $right = $middle;
    }
});

it('appends after an open end and starts a list', function () {
    expect(SortKey::first())->toBe('a0');
    expect(strcmp('a0', SortKey::after('a0')))->toBeLessThan(0);
    expect(strcmp(SortKey::between(null, 'a0'), 'a0'))->toBeLessThan(0);
});
