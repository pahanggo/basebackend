<?php

namespace Gis\Support;

use Gis\Casts\GeometryCast;
use Illuminate\Support\Facades\DB;

/**
 * GeoJSON features to `gis_features` rows, in bulk.
 *
 * This is the import path, not the command path. A feature written by a command
 * is parsed into a `Brick\Geo\Geometry` and goes through `GeometryCast`, which
 * is right for a batch of hundreds and hopeless for three and a half million:
 * the parse, the WKT round trip and the per-feature `ST_Area` query would be
 * the entire cost. Here the geometry never enters PHP as a geometry at all —
 * the source's own GeoJSON is handed to MySQL as a string.
 *
 * What PHP still computes is what MySQL cannot do cheaply or at all:
 *
 * - The bounding box, from the coordinates. `ST_Envelope` is not implemented
 *   for geographic reference systems, and the alternative (reinterpret as SRID
 *   0, take the envelope, pick corners) is four more parses of the same
 *   document per row.
 * - The vertex count. There is no `ST_NumPoints` for a polygon and no way to
 *   loop over rings in an expression, and the WKB-layout arithmetic that would
 *   substitute for it only holds for a single polygon; these sources return
 *   multipolygons too.
 *
 * `area_m2` is the exception and stays in SQL, because it has to be geodesic:
 * `ST_Area` on SRID 4326 returns square metres, nothing in `brick/geo` is
 * geodesic, and GEOS would return square degrees. It is computed by a second
 * statement over the rows just written rather than inline in the INSERT,
 * because inline means binding the same GeoJSON document twice — and a state
 * boundary is a nineteen-megabyte document. That is what exhausted a 256 MB
 * limit on `Sempadan Daerah` the first time this ran.
 */
final class FeatureIngest
{
    /**
     * Rows per INSERT.
     *
     * Each row binds nine placeholders, and MySQL's protocol caps a prepared
     * statement at 65,535 of them. 500 leaves the cap a wide margin while still
     * amortising the round trip.
     */
    public const ROWS_PER_INSERT = 500;

    /**
     * Bytes of geometry per INSERT, whichever limit is reached first.
     *
     * A row count alone is the wrong unit when one row can be a nineteen
     * megabyte state outline and the next a twenty metre house plot: 500
     * cadastral parcels are 160 KB, 500 administrative boundaries are several
     * gigabytes. PHP holds every binding in memory until the statement
     * executes, so this is the limit that actually binds.
     */
    public const BYTES_PER_INSERT = 8 * 1024 * 1024;

    /**
     * @param  array<int, array<string, mixed>>  $features  decoded GeoJSON features
     * @param  array<string, array{0: string, 1: string}>  $attributes  service field => [property, type]
     * @return int rows written
     */
    public static function write(int $layerId, array $features, array $attributes): int
    {
        $rows = [];

        foreach ($features as $feature) {
            $row = self::row($feature, $attributes);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $written = 0;
        $batch = [];
        $bytes = 0;

        foreach ($rows as $row) {
            $batch[] = $row;
            $bytes += strlen($row['geojson']);

            if (count($batch) >= self::ROWS_PER_INSERT || $bytes >= self::BYTES_PER_INSERT) {
                $written += self::insert($layerId, $batch);
                $batch = [];
                $bytes = 0;
            }
        }

        return $written + self::insert($layerId, $batch);
    }

    /**
     * One feature, or null if it carries nothing this table can hold.
     *
     * Only polygons are kept. That is not a limitation of the schema — `geom`
     * is a generic geometry column — but of `area_m2`: `ST_Area` raises on a
     * point or a line, and a layer whose cull column is a lie is worse than a
     * layer missing a row. Both iPLAN sources are declared
     * `esriGeometryPolygon`, so anything else is a surprise worth dropping
     * rather than guessing at.
     *
     * @param  array<string, mixed>  $feature
     * @param  array<string, array{0: string, 1: string}>  $attributes
     * @return array<string, mixed>|null
     */
    private static function row(array $feature, array $attributes): ?array
    {
        $geometry = $feature['geometry'] ?? null;

        if (! is_array($geometry) || ! isset($geometry['type'], $geometry['coordinates'])) {
            return null;
        }

        if (! in_array($geometry['type'], ['Polygon', 'MultiPolygon'], true)) {
            return null;
        }

        $properties = (array) ($feature['properties'] ?? []);
        $sourceId = $properties['OBJECTID'] ?? $feature['id'] ?? null;

        if ($sourceId === null) {
            return null;
        }

        unset($properties['OBJECTID']);

        [$box, $vertices] = self::boxAndVertices($geometry['coordinates']);

        if ($vertices === 0) {
            return null;
        }

        // The service's field name on the left, the name this application uses
        // on the right. The services carry names truncated to a shapefile's
        // ten-character column limit, and storing those would make
        // `nama_neger` the name every popup and export says forever.
        $kept = [];

        foreach ($attributes as $field => [$property]) {
            $kept[$property] = $properties[$field] ?? null;
        }

        // `_src` is added after sanitising, never through it: the sanitiser
        // refuses the key precisely so that a command cannot forge provenance.
        $clean = PropertySanitizer::clean($kept);
        $clean[PropertySanitizer::SOURCE_ID_KEY] = (int) $sourceId;

        return [
            'geojson' => json_encode($geometry, JSON_THROW_ON_ERROR),
            'box' => $box,
            'vertices' => $vertices,
            'properties' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * The bounding box and the vertex count, in one pass over the coordinates.
     *
     * Two passes would be two traversals of the largest structure in the
     * import; one source row in the neighbouring cadastre has 2,164 interior
     * rings.
     *
     * @param  array<mixed>  $coordinates
     * @return array{0: array{0: float, 1: float, 2: float, 3: float}, 1: int}
     */
    private static function boxAndVertices(array $coordinates): array
    {
        $minx = $miny = INF;
        $maxx = $maxy = -INF;
        $vertices = 0;

        $walk = function (array $node) use (&$walk, &$minx, &$miny, &$maxx, &$maxy, &$vertices): void {
            if ($node === []) {
                return;
            }

            $first = $node[array_key_first($node)];

            if (is_numeric($first)) {
                $x = (float) $node[0];
                $y = (float) $node[1];

                $minx = min($minx, $x);
                $miny = min($miny, $y);
                $maxx = max($maxx, $x);
                $maxy = max($maxy, $y);
                $vertices++;

                return;
            }

            foreach ($node as $child) {
                $walk((array) $child);
            }
        };

        $walk($coordinates);

        if ($vertices === 0) {
            return [[0.0, 0.0, 0.0, 0.0], 0];
        }

        return [[$minx, $miny, $maxx, $maxy], $vertices];
    }

    /**
     * One statement to write the rows, one to give them their areas.
     *
     * Two statements rather than one because `ST_Area` inline would need the
     * GeoJSON bound a second time, doubling the peak memory for no benefit;
     * reading it back off the stored column costs MySQL a parse it has already
     * done. The id floor is exact: a multi-row INSERT reserves a contiguous
     * block of auto-increment values, so nothing else in this layer can sit
     * above the first id it was given.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function insert(int $layerId, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $connection = DB::connection(config('gis.connection'));

        $values = implode(',', array_fill(
            0,
            count($rows),
            '(?, '.GeometryCast::geoJsonPlaceholder().', ?, ?, ?, ?, ?, CAST(? AS JSON), 1, NOW(), NOW())',
        ));

        $bindings = [];

        foreach ($rows as $row) {
            [$minx, $miny, $maxx, $maxy] = $row['box'];

            $bindings[] = $layerId;
            $bindings[] = $row['geojson'];
            $bindings[] = $minx;
            $bindings[] = $miny;
            $bindings[] = $maxx;
            $bindings[] = $maxy;
            $bindings[] = $row['vertices'];
            $bindings[] = $row['properties'];
        }

        $written = $connection->affectingStatement(
            'INSERT INTO gis_features
                (layer_id, geom, minx, miny, maxx, maxy, vertex_count, properties, version, created_at, updated_at)
             VALUES '.$values,
            $bindings,
        );

        $firstId = (int) $connection->getPdo()->lastInsertId();

        $connection->affectingStatement(
            'UPDATE gis_features SET area_m2 = ST_Area(geom) WHERE layer_id = ? AND id >= ?',
            [$layerId, $firstId],
        );

        return $written;
    }
}
