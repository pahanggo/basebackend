<?php

use App\Models\User;
use Gis\Http\Controllers\Api\FeatureReadController;
use Gis\Models\Feature;
use Gis\Models\Layer;
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
