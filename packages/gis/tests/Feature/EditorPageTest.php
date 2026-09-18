<?php

use App\Models\User;
use Gis\Testing\RefreshesGisDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshesGisDatabase::class);

/** @return User a user holding the GIS module permission */
function gisUser(): User
{
    Permission::findOrCreate(config('gis.route.permission'), 'web');

    return tap(User::factory()->create())
        ->givePermissionTo(config('gis.route.permission'));
}

it('redirects a guest away from the editor', function () {
    $this->get(route('gis.editor'))->assertRedirect();
});

it('refuses a signed-in user without the module permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('gis.editor'))
        ->assertForbidden();
});

it('renders the editor with the configured tile url in the bootstrap blob', function () {
    $response = $this->actingAs(gisUser())->get(route('gis.editor'));

    $response->assertOk();

    // The client reads one JSON blob, so assert against the decoded blob
    // rather than the markup around it.
    preg_match('#<script type="application/json" id="gis-bootstrap">(.*?)</script>#s', $response->getContent(), $m);
    $bootstrap = json_decode($m[1] ?? '', true);

    expect($bootstrap)->toBeArray();
    expect($bootstrap['basemap']['url'])->toBe(config('services.map_tiles.url'));
    expect($bootstrap['capabilities']['editMinZoom'])->toBe(config('gis.read.edit_min_zoom'));
    expect($bootstrap['csrfToken'])->not->toBeEmpty();

    // Leaflet is the vendored global, loaded once, before the bundle.
    $response->assertSee('packages/leaflet/dist/leaflet.js', false);
    expect(substr_count($response->getContent(), 'leaflet.js'))->toBe(1);

    // Full bleed: the plain layout's centred container is overridden.
    $response->assertSee('class="gis-shell"', false);
    $response->assertDontSee('<div class="container">', false);

    // And a way back, since the plain layout has no chrome of its own.
    $response->assertSee(backpack_url('dashboard'), false);
});

it('serves the api health check behind the same guard', function () {
    $this->getJson(route('gis.api.health'))->assertUnauthorized();

    $this->actingAs(gisUser())
        ->getJson(route('gis.api.health'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('capabilities.maxBatch', config('gis.write.max_batch'));
});
