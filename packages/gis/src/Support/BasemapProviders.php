<?php

namespace Gis\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The basemap list, from the tile service rather than from config.
 *
 * Duplicating it in config means a provider added to the service does not
 * appear without a deployment, and one removed keeps being offered until
 * someone notices the broken tiles. Reading it fixes both.
 *
 * **Three levels of fallback, and they are the point.** Live list, then the
 * cached one, then the configured default alone. A basemap the user cannot
 * change beats a map that will not load, so this class never throws and never
 * returns an empty list (specification section 13).
 */
class BasemapProviders
{
    /**
     * Basemaps and overlays, classified.
     *
     * @return array{basemaps: array<int, string>, overlays: array<int, string>, source: string}
     */
    public function all(): array
    {
        [$providers, $source] = $this->providers();

        $prefix = (string) config('gis.basemaps.overlay_prefix');

        // Classified by prefix, so a sixth `owm-` provider is handled without a
        // code change. Presenting weather in the same mutually-exclusive list as
        // basemaps would let a user select "precipitation" and get weather over
        // a void.
        $overlays = array_values(array_filter($providers, fn (string $p) => str_starts_with($p, $prefix)));
        $basemaps = array_values(array_filter($providers, fn (string $p) => ! str_starts_with($p, $prefix)));

        if ($basemaps === []) {
            $basemaps = [(string) config('gis.basemaps.default')];
        }

        return ['basemaps' => $basemaps, 'overlays' => $overlays, 'source' => $source];
    }

    /**
     * @return array{0: array<int, string>, 1: string}
     */
    protected function providers(): array
    {
        $key = (string) config('gis.basemaps.cache_key');

        $cached = Cache::get($key);

        if (is_array($cached) && $cached !== []) {
            return [$cached, 'cache'];
        }

        try {
            $response = Http::timeout((int) config('gis.basemaps.timeout_seconds'))
                ->get((string) config('gis.basemaps.providers_url'));

            $providers = $response->successful()
                ? array_values(array_filter((array) $response->json('providers'), 'is_string'))
                : [];

            if ($providers !== []) {
                Cache::put($key, $providers, now()->addHours((int) config('gis.basemaps.cache_hours')));

                return [$providers, 'service'];
            }
        } catch (Throwable $e) {
            // Logged, not raised. The editor loading with one basemap is a far
            // better outcome than the editor not loading, and the operator
            // needs to know the service is down either way.
            Log::warning('gis: basemap provider list unavailable', ['message' => $e->getMessage()]);
        }

        return [[(string) config('gis.basemaps.default')], 'default'];
    }
}
