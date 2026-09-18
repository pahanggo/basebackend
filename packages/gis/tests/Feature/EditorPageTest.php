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

/** The one JSON blob the client configures itself from. */
function bootstrapBlob(Illuminate\Testing\TestResponse $response): array
{
    preg_match('#<script type="application/json" id="gis-bootstrap">(.*?)</script>#s', $response->getContent(), $m);

    return json_decode($m[1] ?? '', true) ?? [];
}

it('renders the editor with everything the client needs in one blob', function () {
    $response = $this->actingAs(gisUser())->get(route('gis.editor'));

    $response->assertOk();

    // The client reads one JSON blob, so assert against the decoded blob
    // rather than the markup around it.
    $bootstrap = bootstrapBlob($response);

    expect($bootstrap)->toBeArray();
    expect($bootstrap['capabilities']['editMinZoom'])->toBe(config('gis.read.edit_min_zoom'));
    expect($bootstrap['csrfToken'])->not->toBeEmpty();
    expect($bootstrap['clientId'])->not->toBeEmpty();

    // A user with no maps gets none, and the client opens the map browser
    // rather than rendering a blank canvas.
    expect($bootstrap['map'])->toBeNull();

    // Leaflet is the vendored global, loaded once, before the bundle.
    $response->assertSee('packages/leaflet/dist/leaflet.js', false);
    expect(substr_count($response->getContent(), 'leaflet.js'))->toBe(1);

    // Full bleed: the plain layout's centred container is overridden.
    $response->assertSee('class="gis-shell"', false);
    $response->assertDontSee('<div class="container">', false);

    // And a way back, since the plain layout has no chrome of its own.
    $response->assertSee(backpack_url('dashboard'), false);
});

it('inlines the open map so the editor renders without a round trip', function () {
    $user = gisUser();
    $map = Gis\Models\Map::factory()->create(['owner_id' => $user->id, 'name' => 'Site survey']);

    $bootstrap = bootstrapBlob($this->actingAs($user)->get(route('gis.editor')));

    expect($bootstrap['map']['id'])->toBe($map->id);
    expect($bootstrap['map']['name'])->toBe('Site survey');
    expect($bootstrap['map']['role'])->toBe('owner');

    // The tile template comes from the application's own config, and this
    // package defines none of its own.
    expect($bootstrap['map']['basemaps']['urlTemplate'])->toBe(config('services.map_tiles.url'));
});

it('opens the map named in the query string, and ignores one the user may not see', function () {
    $user = gisUser();

    $mine = Gis\Models\Map::factory()->create(['owner_id' => $user->id, 'name' => 'Mine']);
    $theirs = Gis\Models\Map::factory()->create(['owner_id' => gisUser()->id, 'name' => 'Theirs']);

    $chosen = bootstrapBlob($this->actingAs($user)->get(route('gis.editor', ['map' => $mine->id])));

    expect($chosen['map']['id'])->toBe($mine->id);

    // Falls back to a map they may open rather than honouring the id.
    $refused = bootstrapBlob($this->actingAs($user)->get(route('gis.editor', ['map' => $theirs->id])));

    expect($refused['map']['id'])->toBe($mine->id);
});

it('tells the client whether restoring is offered at all', function () {
    expect(bootstrapBlob($this->actingAs(gisUser())->get(route('gis.editor')))['canRestore'])->toBeFalse();
    expect(bootstrapBlob($this->actingAs(gisAdministrator())->get(route('gis.editor')))['canRestore'])->toBeTrue();
});

it('serves the api health check behind the same guard', function () {
    $this->getJson(route('gis.api.health'))->assertUnauthorized();

    $this->actingAs(gisUser())
        ->getJson(route('gis.api.health'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('capabilities.maxBatch', config('gis.write.max_batch'));
});

it('gives the client a same-origin API base that no proxy can get wrong', function () {
    // Absolute URLs in this blob are a trap behind a TLS-terminating proxy:
    // the application generates http:// unless it trusts the proxy, and a URL
    // inside JSON is one of the few Cloudflare's HTTPS rewriting cannot fix.
    // Relative means it takes the page's own scheme and host, always.
    $bootstrap = bootstrapBlob(test()->actingAs(gisUser())->get(route('gis.editor')));

    expect($bootstrap['apiBase'])->toBe('/api/geo');
    expect($bootstrap['apiBase'])->not->toStartWith('http');
});
