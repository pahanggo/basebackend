<?php

use Gis\Models\Feature;
use Gis\Models\Layer;
use Gis\Models\Map;
use Gis\Models\MapLayer;
use Gis\Testing\RefreshesGisDatabase;

uses(RefreshesGisDatabase::class);

/**
 * What the layer tree is built from.
 *
 * The tree itself is client-side and its arithmetic is covered by the Node
 * tests in `tests/js`. What matters here is the contract between them: the
 * bootstrap has to carry everything a row needs, because the tree does not
 * issue a second request to draw itself.
 */
it('carries every field a tree row renders', function () {
    [$map, $layer, $placement] = editableMap();

    $placement->forceFill(['visible' => false, 'opacity' => 0.5, 'min_zoom' => 12, 'max_zoom' => 18])->save();

    $bootstrap = $this->actingAs(App\Models\User::findOrFail($map->owner_id))
        ->getJson(route('gis.api.maps.show', ['map' => $map->id]))
        ->assertOk()
        ->json();

    $entry = collect($bootstrap['layers'])->firstWhere('layerId', $layer->id);

    // Order and nesting.
    expect($entry)->toHaveKeys(['placementId', 'parentId', 'sortKey']);

    // What the row draws.
    expect($entry['visible'])->toBeFalse();
    expect($entry['opacity'])->toBe(0.5);
    expect($entry['minZoom'])->toBe(12);
    expect($entry['maxZoom'])->toBe(18);
    expect($entry)->toHaveKeys(['locked', 'access', 'shared', 'ownedHere', 'kind']);

    // Both versions, because a row commands against two different rows.
    expect($entry)->toHaveKeys(['placementVersion', 'layerVersion']);
});

it('carries the layer extent as a bounding box, longitude first', function () {
    [$map, $layer] = editableMap();

    Feature::factory()->at(103.32, 3.80)->create(['layer_id' => $layer->id]);
    Feature::factory()->at(103.40, 3.90)->create(['layer_id' => $layer->id]);

    $layer->extent = Gis\Casts\GeometryCast::toGeometry(
        'POLYGON((103.32 3.80, 103.40 3.80, 103.40 3.90, 103.32 3.90, 103.32 3.80))'
    );
    $layer->save();

    $entry = collect($this->actingAs(App\Models\User::findOrFail($map->owner_id))
        ->getJson(route('gis.api.maps.show', ['map' => $map->id]))
        ->json('layers'))->firstWhere('layerId', $layer->id);

    // Longitude first. Swapped, "zoom to layer" flies to a latitude of 103,
    // which does not exist, and Leaflet clamps rather than complaining.
    expect($entry['extent'])->toBe([103.32, 3.8, 103.4, 3.9]);
});

it('sends a null extent rather than omitting it, for a layer with no features', function () {
    [$map, $layer] = editableMap();

    $entry = collect($this->actingAs(App\Models\User::findOrFail($map->owner_id))
        ->getJson(route('gis.api.maps.show', ['map' => $map->id]))
        ->json('layers'))->firstWhere('layerId', $layer->id);

    expect($entry)->toHaveKey('extent');
    expect($entry['extent'])->toBeNull();
});

it('offers the basemaps and overlays the control panel is built from', function () {
    [$map] = editableMap();

    $basemaps = $this->actingAs(App\Models\User::findOrFail($map->owner_id))
        ->getJson(route('gis.api.maps.show', ['map' => $map->id]))
        ->json('basemaps');

    // Classified server-side by prefix, so a sixth weather overlay needs no
    // client change — which is the whole reason the split is not a hardcoded
    // list in the panel.
    expect($basemaps)->toHaveKeys(['basemaps', 'overlays', 'default', 'urlTemplate']);
    expect($basemaps['basemaps'])->not->toBeEmpty();

    foreach ($basemaps['overlays'] as $overlay) {
        expect($overlay)->toStartWith(config('gis.basemaps.overlay_prefix'));
    }

    foreach ($basemaps['basemaps'] as $basemap) {
        expect($basemap)->not->toStartWith(config('gis.basemaps.overlay_prefix'));
    }
});

it('remembers the weather overlays as part of the view, unversioned', function () {
    [$map] = editableMap();

    $before = $map->version;

    $this->actingAs(App\Models\User::findOrFail($map->owner_id))
        ->putJson(route('gis.api.maps.view', ['map' => $map->id]), [
            'center' => [103.326, 3.8077],
            'zoom' => 14,
            'basemap' => 'grayscale',
            'overlays' => ['owm-clouds', 'owm-wind'],
        ])
        ->assertNoContent();

    $map->refresh();

    expect($map->view_state['overlays'])->toBe(['owm-clouds', 'owm-wind']);
    expect($map->view_state['basemap'])->toBe('grayscale');

    // The map's version is the replay sequence for its command log. Bumping it
    // because somebody toggled a rain layer would thread a hole through that
    // sequence and invalidate every other client several times a session.
    expect($map->version)->toBe($before);
});

it('clears the overlays when sent an empty list, rather than keeping the old ones', function () {
    [$map] = editableMap();
    $user = App\Models\User::findOrFail($map->owner_id);

    $this->actingAs($user)->putJson(route('gis.api.maps.view', ['map' => $map->id]), [
        'center' => [103.326, 3.8077], 'zoom' => 14, 'overlays' => ['owm-temp'],
    ])->assertNoContent();

    // Turning the last overlay off is a state the user chose. The view write
    // merges, so an absent key means "unchanged" — a client that stopped
    // sending the key when the list emptied could never turn one off, and the
    // overlay came back on every reload.
    $this->actingAs($user)->putJson(route('gis.api.maps.view', ['map' => $map->id]), [
        'center' => [103.326, 3.8077], 'zoom' => 14, 'overlays' => [],
    ])->assertNoContent();

    expect($map->refresh()->view_state['overlays'])->toBe([]);
});

it('places a group in the tree as an ordinary node', function () {
    [$map] = editableMap();

    $group = Layer::factory()->create(['owner_map_id' => $map->id, 'name' => 'Base data', 'kind' => 'group']);

    MapLayer::factory()->create(['map_id' => $map->id, 'layer_id' => $group->id, 'sort_key' => 'a0']);

    $entry = collect($this->actingAs(App\Models\User::findOrFail($map->owner_id))
        ->getJson(route('gis.api.maps.show', ['map' => $map->id]))
        ->json('layers'))->firstWhere('layerId', $group->id);

    // A group is a layer of kind `group` plus a placement, so it is dragged,
    // hidden, faded and reordered by the same commands as everything else
    // rather than needing a parallel vocabulary.
    expect($entry['kind'])->toBe('group');
    expect($entry)->toHaveKeys(['visible', 'opacity', 'sortKey', 'parentId']);
});
