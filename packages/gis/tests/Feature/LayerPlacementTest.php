<?php

use Gis\Models\Layer;
use Gis\Models\Map;
use Gis\Models\MapLayer;
use Gis\Testing\RefreshesGisDatabase;

uses(RefreshesGisDatabase::class);

it('places one layer in many maps without copying anything', function () {
    // The reason identity and placement are separate tables: the imported
    // cadastral base exists once and is placed read-only everywhere. Without
    // the split, a second map over the same cadastre means a second copy of
    // 1.4 million rows.
    $base = Layer::factory()->global()->create(['name' => 'Lot', 'feature_count' => 672112]);

    $first = Map::factory()->create();
    $second = Map::factory()->create();

    MapLayer::factory()->readOnly()->create(['map_id' => $first->id, 'layer_id' => $base->id]);
    MapLayer::factory()->readOnly()->create(['map_id' => $second->id, 'layer_id' => $base->id]);

    expect(Layer::count())->toBe(1);
    expect($base->placements()->count())->toBe(2);
    expect($base->isGlobal())->toBeTrue();
});

it('refuses to place the same layer in one map twice', function () {
    $map = Map::factory()->create();
    $layer = Layer::factory()->global()->create();

    MapLayer::factory()->create(['map_id' => $map->id, 'layer_id' => $layer->id]);

    expect(fn () => MapLayer::factory()->create(['map_id' => $map->id, 'layer_id' => $layer->id]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('nests placements under placements, not under layers', function () {
    $map = Map::factory()->create();

    $group = MapLayer::factory()->create([
        'map_id' => $map->id,
        'layer_id' => Layer::factory()->create(['kind' => 'group', 'owner_map_id' => $map->id]),
        'sort_key' => 'a0',
    ]);

    $child = MapLayer::factory()->create([
        'map_id' => $map->id,
        'layer_id' => Layer::factory()->create(['owner_map_id' => $map->id]),
        'parent_id' => $group->id,
        'sort_key' => 'a0V',
    ]);

    expect($child->parent->is($group))->toBeTrue();
    expect($group->children()->pluck('id')->all())->toBe([$child->id]);
});

it('carries a version on identity and on placement separately', function () {
    $map = Map::factory()->create();
    $layer = Layer::factory()->create(['owner_map_id' => $map->id]);
    $placement = MapLayer::factory()->create(['map_id' => $map->id, 'layer_id' => $layer->id]);

    // A restyle guards on the layer's version; a reorder on the placement's.
    expect($layer->version)->toBe(1);
    expect($placement->version)->toBe(1);

    $layer->update(['style' => ['stroke' => '#ff0000'], 'version' => 2]);

    expect($placement->fresh()->version)->toBe(1);
});
