<?php

namespace Gis\Database\Seeders;

use Gis\Models\Feature;
use Gis\Models\Layer;
use Gis\Support\ArcGisSource;
use Gis\Support\FeatureIngest;
use Gis\Support\ImportLedger;
use Gis\Support\LayerRollup;
use Illuminate\Database\Seeder;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The PLANMalaysia layers, imported from their ArcGIS services.
 *
 *     php artisan db:seed --class='Gis\Database\Seeders\GunatanahSeeder'
 *
 * Two land-use layers from `iPLAN` (Gunatanah Semasa, 2.86 million features;
 * Gunatanah Zoning, 738 thousand) and six administrative boundary layers from
 * `SCHARMS` (Parlimen, DUN, PBT, Negeri, Daerah, Mukim — 2,549 between them).
 * The set lives in `config('gis.import.arcgis.sources')`; adding a service is a
 * config entry, not code.
 *
 * Narrow it, start over, or stop early with environment variables — `db:seed`
 * passes no options through to a seeder, and inventing a second command for
 * something that is already a seeder would be worse:
 *
 *     GUNATANAH_SOURCES=negeri,daerah,mukim php artisan db:seed --class=...
 *     GUNATANAH_FRESH=1 php artisan db:seed --class=...
 *     GUNATANAH_WINDOWS=3 php artisan db:seed --class=...
 *     GUNATANAH_TRUNCATE=1 php artisan db:seed --class=...
 *
 * `GUNATANAH_TRUNCATE` is for the one import that establishes a deployment: it
 * empties `gis_features`, `gis_layers` and `gis_map_layer`, resets their
 * auto-increment, and throws away every ledger, so the layers come back with
 * ids from 1. It destroys hand-drawn layers and every map's layer tree along
 * with the imported base — the placements cannot outlive the layers they point
 * at — so it is opt-in, once, before anyone has drawn anything.
 *
 * The last one stops after three windows. It exists so the real services can be
 * smoke-tested in a minute — field names, axis order, the computed columns —
 * before anyone commits to an overnight run, and it costs nothing because the
 * ledger makes a capped run simply a resumable one.
 *
 * It is deliberately NOT called from `DatabaseSeeder`. This writes 3.6 million
 * rows over a few hours against a third party's public service; that is a
 * decision someone makes once per deployment, not a side effect of setting up a
 * development database.
 *
 * The layers it creates are global — `owner_map_id` NULL, `locked` true — and
 * reach individual maps through the layer library.
 * Importing per map would mean 3.6 million rows per map, which is the exact
 * thing the identity/placement split exists to prevent.
 *
 * Interrupt it freely. Every window of OBJECTIDs is committed in its own
 * transaction and recorded in the ledger only once committed, so a re-run picks
 * up precisely the windows that did not finish.
 */
class GunatanahSeeder extends Seeder
{
    /** Source keys to import; empty means every source in the config. */
    public array $only = [];

    /** Delete the layer's features and its ledger first, then import again. */
    public bool $fresh = false;

    /** Stop after this many windows per source; 0 imports the whole range. */
    public int $maxWindows = 0;

    /** Empty the layer, placement and feature tables first. See the class docblock. */
    public bool $truncate = false;

    public function run(): void
    {
        $configured = config('gis.import.arcgis.sources');

        if ($this->truncate || env('GUNATANAH_TRUNCATE')) {
            $this->truncateEverything();
        }

        foreach ($this->selected($configured) as $key) {
            if (! isset($configured[$key])) {
                throw new RuntimeException(
                    "Unknown source '{$key}'. Known: ".implode(', ', array_keys($configured)).'.'
                );
            }

            $this->import($key, $configured[$key]);
        }
    }

    /**
     * Empty the tables this seeder fills, and the ones that depend on them.
     *
     * `gis_map_layer` goes too, and it is not optional: a placement names a
     * layer id, there are no foreign keys across this package's connection to
     * enforce it, and a tree of placements pointing at ids that now mean
     * something else is worse than an empty one.
     *
     * `gis_maps`, `gis_measurements` and the command log are left alone. They
     * are the user's work, not the import's, and a map that has lost its layers
     * can be given them again.
     */
    private function truncateEverything(): void
    {
        $connection = DB::connection(config('gis.connection'));

        foreach (['gis_features', 'gis_map_layer', 'gis_layers'] as $table) {
            $connection->table($table)->truncate();
        }

        Storage::disk(config('gis.import.arcgis.progress_disk'))
            ->deleteDirectory(config('gis.import.arcgis.progress_folder'));

        $this->line('<comment>Truncated gis_features, gis_map_layer and gis_layers; ids restart at 1.</comment>');
    }

    /**
     * @param  array<string, mixed>  $configured
     * @return array<int, string>
     */
    private function selected(array $configured): array
    {
        if ($this->only !== []) {
            return $this->only;
        }

        $fromEnv = array_filter(array_map('trim', explode(',', (string) env('GUNATANAH_SOURCES'))));

        return $fromEnv !== [] ? $fromEnv : array_keys($configured);
    }

    /**
     * @param  array{service: string, layer: int, name: string, where?: string, window?: int, attributes: array<string, array{0: string, 1: string}>, style: array<string, mixed>}  $definition
     */
    private function import(string $key, array $definition): void
    {
        $source = ArcGisSource::make(
            $definition['service'],
            $definition['layer'],
            $definition['where'] ?? '1=1',
        );

        $layer = $this->layerFor($definition);
        $ledger = ImportLedger::for($key);

        $this->line("<info>{$definition['name']}</info> from {$source->url()}");

        if ($this->fresh || env('GUNATANAH_FRESH')) {
            $deleted = Feature::query()->where('layer_id', $layer->id)->delete();
            $ledger->forget();

            if (is_file(self::rejectPath($key))) {
                unlink(self::rejectPath($key));
            }
            $this->line("  deleted {$deleted} existing features");
        }

        // An interrupted import leaves rows behind, and the ledger is the only
        // record of which ones. Features without a ledger means the layer was
        // filled some other way, and appending to it would silently double a
        // layer nobody would think to check.
        if ($ledger->count() === 0 && Feature::query()->where('layer_id', $layer->id)->exists()) {
            throw new RuntimeException(sprintf(
                '%s already holds features but has no import ledger at %s. Set GUNATANAH_FRESH=1 to replace them.',
                $definition['name'],
                $ledger->path(),
            ));
        }

        [$lo, $hi, $total] = $source->range();

        // Per source, because a window is a memory budget as much as a page
        // size: a thousand cadastral parcels are 330 KB, while a single state
        // outline is nineteen megabytes of GeoJSON and a thousand boundaries
        // would not fit in any reasonable limit.
        $size = max(1, (int) ($definition['window'] ?? config('gis.import.arcgis.window')));
        $windows = $this->windows($lo, $hi, $size, $ledger);
        $cap = $this->maxWindows ?: (int) env('GUNATANAH_WINDOWS');

        if ($cap > 0 && count($windows) > $cap) {
            $windows = array_slice($windows, 0, $cap);
            $this->line("  capped at {$cap} windows; re-run to continue");
        }

        if ($windows === []) {
            $this->line('  every window already imported');
            $this->finish($key, $layer, $definition, $total, []);

            return;
        }

        $bar = $this->command?->getOutput()->createProgressBar($total);
        $bar?->setProgress(min($total, $ledger->count() * $size));

        $fields = array_keys($definition['attributes']);
        $concurrency = max(1, (int) config('gis.import.arcgis.concurrency'));
        $rejects = [];

        foreach (array_chunk($windows, $concurrency) as $group) {
            $this->importGroup($key, $source, $layer, $definition, $ledger, $group, $fields, $rejects);
            $bar?->advance(count($group) * $size);
        }

        $bar?->finish();
        $this->command?->getOutput()->newLine(2);

        $this->finish($key, $layer, $definition, $total, $rejects);
    }

    /**
     * Fetch a group of windows at once, then write them.
     *
     * The writes are sequential and each is its own transaction, because the
     * ledger entry and the rows it vouches for have to commit together. The
     * fetches are what benefit from concurrency, and they are the part that
     * takes the time.
     *
     * A window the service refused comes back from the pool as an error
     * document rather than an exception, and is re-fetched through
     * `ArcGisSource::window()`, which bisects it to find the unservable record
     * and keeps the rest.
     *
     * @param  array<int, array{0: int, 1: int}>  $group
     * @param  array<int, string>  $fields
     * @param  array<int, int>  $rejects
     */
    private function importGroup(
        string $key,
        ArcGisSource $source,
        Layer $layer,
        array $definition,
        ImportLedger $ledger,
        array $group,
        array $fields,
        array &$rejects,
    ): void {
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (array $window) => $source->poolWindow($pool, $window[0], $window[1], $fields),
            $group,
        ));

        foreach ($group as $index => $window) {
            $found = [];
            $features = $this->featuresFor($source, $responses[$index], $window, $fields, $found);

            DB::connection(config('gis.connection'))->transaction(
                function () use ($layer, $definition, $features, $ledger, $window) {
                    FeatureIngest::write($layer->id, $features, $definition['attributes']);

                    $ledger->mark($window[0]);
                }
            );

            // Written as the window commits, never accumulated for the end.
            // Marking the window done and remembering what it dropped are one
            // fact: once the ledger says a window is finished, no later run
            // will fetch it again, so an id not recorded here can never be
            // recovered from the service. Holding the list in memory lost it
            // to every interruption — which, on a run measured in hours
            // against someone else's service, is the normal case.
            if ($found !== []) {
                $this->recordRejects($key, $found);
                $rejects = array_merge($rejects, $found);
            }
        }
    }

    /**
     * @param  array{0: int, 1: int}  $window
     * @param  array<int, string>  $fields
     * @param  array<int, int>  $rejects
     * @return array<int, array<string, mixed>>
     */
    private function featuresFor(
        ArcGisSource $source,
        mixed $response,
        array $window,
        array $fields,
        array &$rejects,
    ): array {
        if (! $response instanceof Throwable) {
            try {
                return $source->featureCollection($response);
            } catch (Throwable) {
                // Fall through: re-fetch it one window at a time, which is
                // where the bisection lives.
            }
        }

        return $source->window($window[0], $window[1], $fields, $rejects);
    }

    /**
     * The windows still to do, as [from, to] OBJECTID pairs.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function windows(int $lo, int $hi, int $size, ImportLedger $ledger): array
    {
        $windows = [];

        for ($from = $lo; $from <= $hi; $from += $size) {
            if (! $ledger->has($from)) {
                $windows[] = [$from, min($hi, $from + $size - 1)];
            }
        }

        return $windows;
    }

    /**
     * Find or create the layer.
     *
     * `locked` because this is somebody else's authoritative data: a reference
     * base to draw against, not something to be hand-edited into disagreement
     * with its source.
     *
     * `attr_schema` describes the STORED property names, not the service's
     * field names: it is what the client builds its attribute table and its
     * classification pickers from, and the service's names never reach it.
     *
     * @param  array{name: string, attributes: array<string, array{0: string, 1: string}>, style: array<string, mixed>}  $definition
     */
    private function layerFor(array $definition): Layer
    {
        return Layer::firstOrCreate(
            ['owner_map_id' => null, 'name' => $definition['name']],
            [
                'kind' => 'vector',
                'locked' => true,
                'style' => $definition['style'],
                'attr_schema' => array_values(array_map(
                    fn (array $attribute) => ['name' => $attribute[0], 'type' => $attribute[1]],
                    $definition['attributes'],
                )),
            ],
        );
    }

    /**
     * The ids the service refused, appended as they are found.
     *
     * @param  array<int, int>  $ids
     */
    private function recordRejects(string $key, array $ids): void
    {
        file_put_contents(
            self::rejectPath($key),
            implode(PHP_EOL, $ids).PHP_EOL,
            FILE_APPEND,
        );
    }

    public static function rejectPath(string $key): string
    {
        return storage_path("app/gis-arcgis-rejects-{$key}.txt");
    }

    /** @param array<int, int> $rejects */
    private function finish(string $key, Layer $layer, array $definition, int $total, array $rejects): void
    {
        if ($rejects !== []) {
            $this->line(sprintf(
                '  <comment>%d records the service could not serve; ids in %s</comment>',
                count($rejects),
                self::rejectPath($key),
            ));
        }

        $rollup = LayerRollup::apply($layer);

        $this->line(sprintf(
            '  <info>%s</info>: %s of %s features, extent %s',
            $definition['name'],
            number_format($rollup['count']),
            number_format($total),
            LayerRollup::describe($rollup),
        ));
    }

    private function line(string $message): void
    {
        $this->command?->getOutput()->writeln($message);
    }
}
