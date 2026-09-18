<?php

use App\Models\User;
use Gis\Casts\GeometryCast;
use Gis\Http\Controllers\Api\FeatureReadController;
use Gis\Models\Feature;
use Gis\Models\Layer;
use Gis\Models\Map;
use Gis\Models\MapLayer;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Spatie\Permission\Models\Permission;

/*
 * Fixtures shared by the feature-read tests, in one file so both encodings are
 * asserted against the same data rather than against two hand-built versions of
 * it that could drift apart.
 */

if (! function_exists('reader')) {
    function reader(): User
    {
        Permission::findOrCreate(config('gis.route.permission'), 'web');

        return tap(User::factory()->create())->givePermissionTo(config('gis.route.permission'));
    }

    /**
     * Parcels of known size around Kuantan: one enormous, three middling, five
     * tiny. Areas are set explicitly rather than computed, so the cull has
     * exact edges to be tested against.
     */
    function seedParcels(Layer $layer): void
    {
        $sizes = [1_000_000.0, 20_000.0, 9_000.0, 6_000.0, 100.0, 90.0, 80.0, 70.0, 60.0];

        foreach ($sizes as $i => $area) {
            Feature::factory()
                ->at(103.320 + $i * 0.002, 3.800)
                ->create(['layer_id' => $layer->id, 'area_m2' => $area]);
        }
    }


    /**
     * A map owned by the given user with one editable vector layer placed in
     * it — the smallest arrangement in which a command is authorised, since
     * every feature write composes the user's role, the placement's access and
     * the layer's lock.
     *
     * @return array{0: Map, 1: Layer, 2: MapLayer}
     */
    function editableMap(?User $user = null): array
    {
        $user ??= reader();

        $map = Map::factory()->create(['owner_id' => $user->id]);
        $layer = Layer::factory()->create(['owner_map_id' => $map->id, 'name' => 'Lot']);

        $placement = MapLayer::factory()->create([
            'map_id' => $map->id,
            'layer_id' => $layer->id,
        ]);

        return [$map, $layer, $placement];
    }

    /** A user who may restore soft-deleted maps and layers. */
    function gisAdministrator(): User
    {
        Permission::findOrCreate(config('gis.route.admin_permission'), 'web');

        return tap(reader())->givePermissionTo(config('gis.route.admin_permission'));
    }

    /** Base64 WKB for a small square, as a command carries geometry. */
    function wkbSquare(float $lng = 103.32, float $lat = 3.80, float $side = 0.0002): string
    {
        return GeometryCast::toWkbBase64(GeometryCast::toGeometry(sprintf(
            'POLYGON((%1$F %2$F, %3$F %2$F, %3$F %4$F, %1$F %4$F, %1$F %2$F))',
            $lng, $lat, $lng + $side, $lat + $side,
        )));
    }

    /**
     * Post a batch, as the client does.
     *
     * @param  array<int, array<string, mixed>>  $commands
     */
    function sendCommands(Map $map, array $commands, ?User $user = null, array $envelope = []): TestResponse
    {
        // Defaults to the map's owner. A fresh `reader()` would be a stranger
        // to the map and every call would come back 403.
        $user ??= User::query()->findOrFail($map->owner_id);

        return test()->actingAs($user)
            ->postJson(route('gis.api.maps.commands', ['map' => $map->id]), [
                'clientId' => 'a3f9c2',
                'seq' => 1,
                'mapVersion' => $map->version,
                'commands' => $commands,
                ...$envelope,
            ]);
    }

    function readViewport(array $query = []): TestResponse
    {
        $layer = Layer::query()->firstOrFail();

        // Explicit, because a test that asked for the binary encoding earlier
        // leaves that Accept header on the shared test instance.
        return test()->actingAs(reader())
            ->withHeaders(['Accept' => FeatureReadController::GEOJSON_TYPE])
            ->get(route('gis.api.layers.features', [
            'layer' => $layer->id,
            ...array_merge(['bbox' => '103.0,3.7,103.6,3.9', 'zoom' => 12], $query),
        ]));
    }

    /**
     * The GeoJSON body, decoded.
     *
     * The readable encoding streams and the binary one does not, so the body
     * has to be taken whichever way it arrived.
     */
    function decodeStream(TestResponse $response): array
    {
        $body = $response->baseResponse instanceof StreamedResponse
            ? $response->streamedContent()
            : $response->getContent();

        return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }
}
