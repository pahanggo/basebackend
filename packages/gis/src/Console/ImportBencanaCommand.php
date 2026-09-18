<?php

namespace Gis\Console;

use Gis\Casts\GeometryCast;
use Gis\Models\Layer;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportBencanaCommand extends Command
{
    protected $signature = 'gis:import-bencana
        {--layer=* : Import only these source keys (lots, usages); defaults to all}
        {--fresh : Delete the existing features of each layer before importing}
        {--resume : Continue an interrupted import from the last source row imported}
        {--chunk= : Rows per INSERT ... SELECT, overriding the configured size}';

    protected $description = 'Import the state cadastre from the bencana database into global GIS layers, once.';

    /** The property key each imported feature carries its source row id under. */
    public const SOURCE_ID_KEY = '_src';

    /**
     * This is not the v2 import pipeline, and deliberately shares nothing with
     * it. The source is a MySQL table on the same server, already
     * `POLYGON NOT NULL SRID 4326`, already spatially indexed. There is no file
     * to sniff, no CRS to negotiate and no streaming parser — so this is a
     * chunked `INSERT ... SELECT` and nothing more. Rows never travel through
     * PHP; 1.4 million of them would be an afternoon and a lot of memory.
     *
     * It runs once per environment. There is no sync: after the import the
     * features belong to this application and are edited here.
     */
    public function handle(): int
    {
        $source = $this->sourceConnection();
        $target = $this->targetConnection();

        if (! $this->sameServer($source, $target)) {
            $this->components->error(
                'The bencana and GIS connections must be on the same MySQL server: the import is a cross-database '
                .'INSERT ... SELECT, so the source rows never pass through PHP. Point both at one server, or copy '
                .'the source database across first.'
            );

            return self::FAILURE;
        }

        $configured = config('gis.import.bencana.layers');
        $selected = $this->option('layer') ?: array_keys($configured);

        foreach ($selected as $key) {
            if (! isset($configured[$key])) {
                $this->components->error("Unknown source '{$key}'. Known: ".implode(', ', array_keys($configured)).'.');

                return self::FAILURE;
            }

            $this->importLayer($key, $configured[$key], $source, $target);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{table: string, name: string, attributes: array<int, string>}  $definition
     */
    protected function importLayer(string $key, array $definition, Connection $source, Connection $target): void
    {
        $this->components->info("Importing {$definition['name']} from {$source->getDatabaseName()}.{$definition['table']}");

        $layer = $this->layerFor($definition);

        $existing = $target->table('gis_features')->where('layer_id', $layer->id)->count();

        if ($this->option('fresh')) {
            $deleted = $target->table('gis_features')->where('layer_id', $layer->id)->delete();
            $this->components->warn("Deleted {$deleted} existing features.");
            $existing = 0;
        }

        $sourceTable = $source->getDatabaseName().'.'.$definition['table'];

        $rejected = $this->rejectedIds($source, $sourceTable);
        $this->reportRejects($key, $definition, $rejected);

        [$min, $max, $total] = $this->sourceRange($source, $sourceTable);

        // An import interrupted halfway leaves rows behind and no marker, so
        // running it again would quietly double them. Every row carries the
        // source id it came from, which makes both the refusal and the resume
        // possible; without the check this is a silent data corruption waiting
        // for someone in a hurry.
        $resumeFrom = null;

        if ($existing > 0) {
            if (! $this->option('resume')) {
                $this->components->error(sprintf(
                    '%s already holds %s features. Re-run with --fresh to replace them, or --resume to continue '
                    .'an interrupted import.',
                    $definition['name'],
                    number_format($existing),
                ));

                return;
            }

            $resumeFrom = $this->lastImportedSourceId($target, $layer->id);
            $min = $resumeFrom + 1;

            $this->components->warn(sprintf(
                'Resuming from source id %s; %s features already present.',
                number_format($min),
                number_format($existing),
            ));
        }

        if ($total === 0) {
            $this->components->warn('Nothing to import.');

            return;
        }

        $chunk = (int) ($this->option('chunk') ?: config('gis.import.bencana.chunk'));
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        $bar->setProgress($existing);

        $imported = 0;

        for ($start = $min; $start <= $max; $start += $chunk) {
            $imported += $this->copyChunk(
                $target,
                $sourceTable,
                $definition,
                $layer->id,
                $start,
                $start + $chunk - 1,
            );

            $bar->setProgress(min($existing + $imported + count($rejected), $total));
        }

        $bar->finish();
        $this->newLine(2);

        $this->rollUpLayer($layer, $target);

        $this->components->info(sprintf(
            '%s: %s features imported, %s rejected, %s in source.',
            $definition['name'],
            number_format($existing + $imported),
            number_format(count($rejected)),
            number_format($total),
        ));
    }

    /**
     * Find or create the layer.
     *
     * `owner_map_id` is NULL: these are global layers, created once for the
     * deployment and placed `read` in every map through `gis_map_layer`.
     * Importing per map would mean 1.4 million rows per map, which is the exact
     * thing the identity/placement split exists to prevent.
     *
     * @param  array{table: string, name: string, attributes: array<int, string>}  $definition
     */
    protected function layerFor(array $definition): Layer
    {
        return Layer::firstOrCreate(
            ['owner_map_id' => null, 'name' => $definition['name']],
            [
                'kind' => 'vector',
                'locked' => true,          // imported base data is not hand-edited
                'style' => ['stroke' => '#8a6d3b', 'weight' => 1, 'fill' => '#f0ad4e', 'fillOpacity' => 0.15],
                'attr_schema' => array_map(
                    fn (string $field) => ['name' => $field, 'type' => $field === 'keluasan' ? 'number' : 'string'],
                    $definition['attributes'],
                ),
            ],
        );
    }

    /**
     * The source rows this import refuses.
     *
     * The specification expected none — the source is described as clean — and
     * it is nearly right: every row is SRID 4326 and in the correct axis order.
     * But `ST_IsValid` rejects 3 of 672,112 lots and 11,635 of 746,104 usages,
     * mostly self-intersections. Importing those silently would put geometry in
     * the database that fails every predicate it is later used in, so they are
     * named here rather than discovered later.
     *
     * Repair is not attempted: MySQL has no `ST_MakeValid`, and
     * `GeometryService::makeValid` does not exist until S7. Re-run this command
     * with `--layer` after that lands to pick them up.
     *
     * @return array<int, int>
     */
    protected function rejectedIds(Connection $source, string $sourceTable): array
    {
        return $source->table(DB::raw($sourceTable))
            ->whereRaw('NOT ST_IsValid(`geometry`)')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @param array<int, int> $rejected */
    protected function reportRejects(string $key, array $definition, array $rejected): void
    {
        if ($rejected === []) {
            return;
        }

        $path = storage_path("app/gis-import-rejects-{$key}.txt");
        file_put_contents($path, implode(PHP_EOL, $rejected).PHP_EOL);

        $this->components->warn(sprintf(
            '%s: %s source rows fail ST_IsValid and will be skipped. Ids written to %s',
            $definition['name'],
            number_format(count($rejected)),
            $path,
        ));
    }

    /**
     * The highest source id already imported into this layer.
     *
     * One full pass over the layer, which is seconds against 672,000 rows and
     * only happens on an explicit `--resume`.
     */
    protected function lastImportedSourceId(Connection $target, int $layerId): int
    {
        $row = $target->selectOne(
            "SELECT MAX(CAST(properties->>'$.".self::SOURCE_ID_KEY."' AS UNSIGNED)) AS last
               FROM gis_features WHERE layer_id = ?",
            [$layerId],
        );

        return (int) ($row->last ?? 0);
    }

    /** @return array{0: int, 1: int, 2: int} */
    protected function sourceRange(Connection $source, string $sourceTable): array
    {
        $row = $source->selectOne("SELECT MIN(id) AS lo, MAX(id) AS hi, COUNT(*) AS total FROM {$sourceTable}");

        return [(int) $row->lo, (int) $row->hi, (int) $row->total];
    }

    /**
     * One chunk, copied inside the server.
     *
     * Three things are computed here that the source does not carry:
     *
     * - `area_m2`, geodesically. `ST_Area` on SRID 4326 returns square metres,
     *   and it agrees with the source's own `keluasan` to about 0.05%. This is
     *   the column the whole area-cull design rests on.
     * - The bounding box. `ST_Envelope` is not implemented for geographic
     *   reference systems, so the geometry is reinterpreted as SRID 0 first.
     *   That is safe precisely because MySQL stores 4326 internally in
     *   longitude-latitude order, so X is longitude and Y is latitude.
     * - The vertex count, out of the WKB layout — see `GeometryCast`.
     *
     *
     * @param  array{table: string, name: string, attributes: array<int, string>}  $definition
     */
    protected function copyChunk(
        Connection $target,
        string $sourceTable,
        array $definition,
        int $layerId,
        int $from,
        int $to,
    ): int {
        $properties = $this->propertiesExpression($definition['attributes']);
        $cartesian = 'ST_SRID(`geometry`, 0)';
        $envelopeRing = "ST_ExteriorRing(ST_Envelope({$cartesian}))";

        return $target->affectingStatement(
            <<<SQL
            INSERT INTO gis_features
                (layer_id, geom, minx, miny, maxx, maxy, area_m2, vertex_count, properties, version, created_at, updated_at)
            SELECT
                ?,
                `geometry`,
                ST_X(ST_PointN({$envelopeRing}, 1)),
                ST_Y(ST_PointN({$envelopeRing}, 1)),
                ST_X(ST_PointN({$envelopeRing}, 3)),
                ST_Y(ST_PointN({$envelopeRing}, 3)),
                ST_Area(`geometry`),
                {$this->vertexCount()},
                {$properties},
                1,
                NOW(),
                NOW()
            FROM {$sourceTable}
            WHERE `id` BETWEEN ? AND ?
              AND ST_IsValid(`geometry`)
            SQL,
            [$layerId, $from, $to],
        );
    }

    protected function vertexCount(): string
    {
        return GeometryCast::vertexCountExpression('geometry');
    }

    /**
     * Source columns become one JSON document.
     *
     * Column names come from config, not from a request, and are checked
     * against the same identifier pattern the generated-column rules use
     * before being interpolated.
     *
     * @param  array<int, string>  $attributes
     */
    protected function propertiesExpression(array $attributes): string
    {
        $pairs = [];

        foreach ($attributes as $field) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $field) !== 1) {
                throw new RuntimeException("Refusing to import attribute '{$field}': not a valid property key.");
            }

            $pairs[] = "'{$field}', `{$field}`";
        }

        // Provenance, and the only thing that makes --resume possible. It is
        // also how the rows rejected here are found again once
        // GeometryService::makeValid exists (S7).
        $pairs[] = "'".self::SOURCE_ID_KEY."', `id`";

        return 'JSON_OBJECT('.implode(', ', $pairs).')';
    }

    /**
     * Extent and feature count.
     *
     * This used to fill `geom_simple` as well. Nothing reads it now: the read
     * returns stored geometry at every zoom, and the area cull decides which
     * features are drawn by dropping whole ones rather than reshaping them.
     */
    protected function rollUpLayer(Layer $layer, Connection $target): void
    {
        $bounds = $target->selectOne(
            'SELECT COUNT(*) AS n, MIN(minx) AS minx, MIN(miny) AS miny, MAX(maxx) AS maxx, MAX(maxy) AS maxy
               FROM gis_features WHERE layer_id = ?',
            [$layer->id],
        );

        $layer->feature_count = (int) $bounds->n;

        if ($bounds->n > 0) {
            $layer->extent = GeometryCast::toGeometry(sprintf(
                'POLYGON((%1$F %2$F, %3$F %2$F, %3$F %4$F, %1$F %4$F, %1$F %2$F))',
                $bounds->minx, $bounds->miny, $bounds->maxx, $bounds->maxy,
            ));
        }

        $layer->save();

        $this->components->twoColumnDetail('Extent', $bounds->n > 0
            ? sprintf('%.3f, %.3f to %.3f, %.3f', $bounds->minx, $bounds->miny, $bounds->maxx, $bounds->maxy)
            : 'empty');
    }

    protected function sourceConnection(): Connection
    {
        return DB::connection(config('gis.import.bencana.connection'));
    }

    protected function targetConnection(): Connection
    {
        return DB::connection(config('gis.connection'));
    }

    protected function sameServer(Connection $source, Connection $target): bool
    {
        $key = fn (Connection $c) => [
            $c->getConfig('host'),
            $c->getConfig('port'),
            $c->getConfig('unix_socket'),
        ];

        return $key($source) === $key($target);
    }
}
