<?php

use App\Models\User;
use Gis\Models\Map;
use Gis\Models\MapUser;
use Gis\Support\MapSlug;
use Gis\Testing\RefreshesGisDatabase;

uses(RefreshesGisDatabase::class);

/**
 * `/app/gis/{slug}`.
 *
 * The slug is what a person pastes to another person, so what matters is that
 * it is stable enough to be worth pasting, unique enough to resolve, and
 * scoped so that guessing one gets you nothing.
 */
it('derives a slug from the name and keeps it unique', function () {
    $first = Map::factory()->create(['name' => 'Pahang Darul Makmur']);
    $second = Map::factory()->create(['name' => 'Pahang Darul Makmur']);
    $third = Map::factory()->create(['name' => 'Pahang Darul Makmur']);

    expect($first->slug)->toBe('pahang-darul-makmur');
    expect($second->slug)->toBe('pahang-darul-makmur-2');
    expect($third->slug)->toBe('pahang-darul-makmur-3');
});

it('follows a rename, because a slug that stops describing its map is worse than a changed URL', function () {
    $map = Map::factory()->create(['name' => 'Draf']);

    expect($map->slug)->toBe('draf');

    $map->update(['name' => 'Rancangan Tempatan Kuantan']);

    expect($map->fresh()->slug)->toBe('rancangan-tempatan-kuantan');
});

it('leaves the slug alone when the name has not changed', function () {
    $map = Map::factory()->create(['name' => 'Pahang']);

    $map->update(['view_state' => ['center' => [103.3, 3.8], 'zoom' => 12]]);

    expect($map->fresh()->slug)->toBe('pahang');
});

it('still produces a slug for a name that transliterates to nothing', function () {
    expect(Map::factory()->create(['name' => '???'])->slug)->toBe('map');
    expect(Map::factory()->create(['name' => '!!!'])->slug)->toBe('map-2');
});

it('keeps a soft-deleted map holding its slug, so a restore cannot collide', function () {
    $deleted = Map::factory()->create(['name' => 'Banjir 2024']);
    $deleted->delete();

    // Restorable for thirty days. Handing its name to a new map now would make
    // the restore fail at the one moment the user needs it to work.
    expect(MapSlug::forName('Banjir 2024'))->toBe('banjir-2024-2');
    expect(Map::factory()->create(['name' => 'Banjir 2024'])->slug)->toBe('banjir-2024-2');
});

it('truncates a long name rather than overflowing the column', function () {
    // 252 characters, which fits `name` and would not fit `slug`.
    $map = Map::factory()->create(['name' => trim(str_repeat('sempadan ', 28))]);

    expect(strlen($map->slug))->toBeLessThanOrEqual(MapSlug::MAX_LENGTH);
});

it('opens the editor on the map the slug names', function () {
    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id, 'name' => 'Rancangan Struktur']);

    MapUser::create(['map_id' => $map->id, 'user_id' => $user->id, 'role' => 'owner']);

    $this->actingAs($user)
        ->get(route('gis.editor.map', ['slug' => 'rancangan-struktur']))
        ->assertOk()
        ->assertSee('rancangan-struktur');
});

it('falls back to the user\'s own map rather than 404ing on a slug they may not open', function () {
    $user = reader();
    $mine = Map::factory()->create(['owner_id' => $user->id, 'name' => 'Peta Saya']);

    MapUser::create(['map_id' => $mine->id, 'user_id' => $user->id, 'role' => 'owner']);

    // Somebody else's map. A shared link from a colleague with wider access is
    // a dead end otherwise, and the map browser is one click away either way.
    Map::factory()->create(['owner_id' => User::factory()->create()->id, 'name' => 'Sulit']);

    $this->actingAs($user)
        ->get(route('gis.editor.map', ['slug' => 'sulit']))
        ->assertOk()
        ->assertSee('peta-saya');
});

it('does not resolve a map by slug on the JSON API, which is keyed by id', function () {
    $user = reader();
    $map = Map::factory()->create(['owner_id' => $user->id, 'name' => 'Pahang']);

    MapUser::create(['map_id' => $map->id, 'user_id' => $user->id, 'role' => 'owner']);

    // `Map::getRouteKeyName()` returning 'slug' would be global, and would
    // rebind every `{map}` on this id-based API — 404ing the whole client.
    $this->actingAs($user)->getJson('/api/geo/maps/'.$map->id)->assertOk();
    $this->actingAs($user)->getJson('/api/geo/maps/pahang')->assertNotFound();
});

it('carries the slug in the bootstrap and the listing', function () {
    [$map] = editableMap();
    $user = User::findOrFail($map->owner_id);

    expect($this->actingAs($user)->getJson(route('gis.api.maps.show', ['map' => $map->id]))->json('slug'))
        ->toBe($map->slug);

    expect($this->actingAs($user)->getJson(route('gis.api.maps.index'))->json('data.0.slug'))
        ->toBe($map->slug);
});
