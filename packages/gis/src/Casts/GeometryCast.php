<?php

namespace Gis\Casts;

use Brick\Geo\Geometry;
use Brick\Geo\Io\GeoJsonReader;
use Brick\Geo\Io\WkbReader;
use Brick\Geo\Io\WkbWriter;
use Brick\Geo\Io\WktReader;
use Brick\Geo\Io\WktWriter;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only place geometry is converted in either direction.
 *
 * MySQL reads SRID 4326 as latitude-longitude, following the EPSG definition.
 * Every GeoJSON file and every GIS tool is longitude-latitude. Mixing them puts
 * geometry in the wrong hemisphere and raises no error at all, which is why
 * `ST_GeomFromText`, `ST_AsText`, `ST_AsBinary` and `ST_GeomFromWKB` appear
 * here and nowhere else in the package — a `grep` assertion in the test suite
 * keeps it that way.
 *
 * On the way in, geometry becomes `ST_GeomFromText(..., 4326, 'axis-order=long-lat')`.
 * On the way out, MySQL hands back its internal format: a 4-byte little-endian
 * SRID followed by standard WKB, already in longitude-latitude order. Verified
 * on this deployment: the bytes after the prefix are identical to
 * `ST_AsBinary(geom, 'axis-order=long-lat')`.
 *
 * @implements CastsAttributes<Geometry|null, Geometry|array<mixed>|string|null>
 */
final class GeometryCast implements CastsAttributes
{
    public const SRID = 4326;

    public const AXIS_ORDER = 'axis-order=long-lat';

    /**
     * WKT this package produces is generated from parsed coordinates, so it can
     * only ever contain these characters. Asserted before the string reaches a
     * query, because it is interpolated rather than bound.
     */
    private const SAFE_WKT = '/^[A-Za-z0-9 ,.()+\-]+$/';

    /**
     * Decode a geometry column into a Brick geometry.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get($model, string $key, $value, array $attributes): ?Geometry
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Geometry) {
            return $value;
        }

        return (new WkbReader)->read(self::stripSridPrefix((string) $value), self::SRID);
    }

    /**
     * Encode a geometry for writing.
     *
     * Accepts a Brick geometry, a GeoJSON array or fragment, or WKT.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set($model, string $key, $value, array $attributes): ?Expression
    {
        if ($value === null) {
            return null;
        }

        return self::expression(self::toGeometry($value));
    }

    /**
     * `ST_GeomFromText(...)` for a geometry, safe to interpolate.
     */
    public static function expression(Geometry $geometry): Expression
    {
        return new Expression(self::literal($geometry));
    }

    /**
     * The same, as a bare SQL fragment for callers assembling a predicate.
     *
     * The geometry is interpolated rather than bound, because MySQL will not
     * take a placeholder inside `ST_GeomFromText`'s options argument. That is
     * safe only because the WKT is generated here from parsed coordinates and
     * checked against a character class that admits no quote — a value that
     * failed to parse never reaches this point.
     */
    public static function literal(Geometry $geometry): string
    {
        $wkt = (new WktWriter)->write($geometry);

        if (preg_match(self::SAFE_WKT, $wkt) !== 1) {
            throw new InvalidArgumentException('Refusing to write geometry whose WKT contains unexpected characters.');
        }

        return sprintf("ST_GeomFromText('%s', %d, '%s')", $wkt, self::SRID, self::AXIS_ORDER);
    }

    /**
     * An explicit `ST_AsBinary` select for a geometry column.
     *
     * A plain `select *` already returns longitude-latitude bytes, so this is
     * for queries that want to be explicit about it, or that select through an
     * expression where the internal format would not survive.
     */
    public static function selectBinary(string $column, ?string $alias = null, ?string $fallback = null): Expression
    {
        $source = $fallback === null
            ? sprintf('`%s`', $column)
            : sprintf('COALESCE(`%s`, `%s`)', $column, $fallback);

        return new Expression(sprintf(
            "ST_AsBinary(%s, '%s') as `%s`",
            $source,
            self::AXIS_ORDER,
            $alias ?? $column,
        ));
    }

    /**
     * SQL selecting a geometry column as GeoJSON.
     *
     * `ST_AsGeoJSON` follows RFC 7946 and emits longitude-latitude regardless
     * of the reference system's declared axis order, so it needs no option —
     * unlike every other function in this file. Verified against
     * `ST_AsText(..., 'axis-order=long-lat')` on real rows.
     */
    public static function selectGeoJson(string $column, ?string $alias = null, ?string $fallback = null): string
    {
        $source = $fallback === null
            ? sprintf('`%s`', $column)
            : sprintf('COALESCE(`%s`, `%s`)', $column, $fallback);

        return sprintf('ST_AsGeoJSON(%s) AS `%s`', $source, $alias ?? $column);
    }

    /**
     * SQL counting every vertex in a polygon column, including hole rings.
     *
     * There is no `ST_NumPoints` for a polygon and no way to loop over rings in
     * an expression — and looping is not academic here: one source row has
     * 2,164 interior rings. So the count comes out of the WKB layout instead,
     * which is exact. A polygon is 1 byte of byte order, 4 of type, 4 of ring
     * count, then per ring 4 bytes of point count and 16 bytes per point:
     *
     *     points = (length - 9 - 4 * rings) / 16
     *
     * Lives here because `ST_AsBinary` does, and that is the whole point of the
     * rule: one file owns the raw spatial calls.
     */
    public static function vertexCountExpression(string $column): string
    {
        return sprintf(
            '((LENGTH(ST_AsBinary(`%1$s`)) - 9 - 4 * (1 + ST_NumInteriorRings(`%1$s`))) / 16)',
            $column,
        );
    }


    /**
     * Read a geometry off the wire.
     *
     * Commands carry geometry as base64 WKB by default and GeoJSON on request
     * (specification section 7). WKB is preferred because it is smaller and
     * because it removes a second place where coordinate precision could be
     * silently truncated — a GeoJSON round trip through a JSON encoder is
     * decimal, and decimal is lossy at the seventh place.
     *
     * @param  string  $encoding  wkb | geojson
     */
    public static function fromWire(string $value, string $encoding = 'wkb'): Geometry
    {
        if ($encoding === 'geojson') {
            return self::toGeometry($value);
        }

        $binary = base64_decode($value, strict: true);

        if ($binary === false || $binary === '') {
            throw new InvalidArgumentException('Geometry is not valid base64 WKB.');
        }

        return (new WkbReader)->read($binary, self::SRID);
    }

    /**
     * Write a geometry for the wire, as base64 WKB.
     *
     * The bytes are longitude-latitude, matching what MySQL stores internally
     * and what every consumer of this API expects.
     */
    public static function toWkbBase64(Geometry $geometry): string
    {
        return base64_encode((new WkbWriter)->write($geometry));
    }

    /**
     * Geodesic area in square metres.
     *
     * `ST_Area` on SRID 4326 IS geodesic and returns m² — verified in S1b
     * against the source cadastre's surveyed areas, which it matched to 0.058%.
     * It is computed here rather than in PHP for that reason: nothing in
     * `brick/geo` is geodesic, and GEOS would return square degrees.
     *
     * One round trip per written feature. That is acceptable because writes are
     * a batch of hundreds at most, unlike the read path where a per-row
     * function call would be the whole cost.
     */
    public static function geodesicArea(Geometry $geometry): float
    {
        if (! $geometry instanceof \Brick\Geo\Polygon && ! $geometry instanceof \Brick\Geo\MultiPolygon) {
            // Points and lines are culled by neither area nor length in v1, and
            // the column contract says zero for them (specification section 6).
            return 0.0;
        }

        $row = DB::connection(config('gis.connection'))
            ->selectOne('select ST_Area('.self::literal($geometry).') as area');

        return (float) ($row->area ?? 0.0);
    }

    /**
     * Every vertex in a geometry, hole rings included.
     *
     * Counted in PHP on the write path, where the geometry is already parsed —
     * unlike the read path, where `vertexCountExpression()` gets it out of the
     * WKB layout because MySQL cannot loop over rings in an expression.
     */
    public static function countVertices(Geometry $geometry): int
    {
        $count = 0;

        foreach (self::coordinateArrays($geometry->toArray()) as $ring) {
            $count += count($ring);
        }

        return $count;
    }

    /**
     * @param  array<mixed>  $coordinates
     * @return iterable<array<mixed>>
     */
    private static function coordinateArrays(array $coordinates): iterable
    {
        if ($coordinates === []) {
            return;
        }

        // A position is a flat list of numbers; anything else is a nesting
        // level to descend through.
        if (is_numeric($coordinates[array_key_first($coordinates)])) {
            yield [$coordinates];

            return;
        }

        $first = $coordinates[array_key_first($coordinates)];

        if (is_array($first) && $first !== [] && is_numeric($first[array_key_first($first)])) {
            yield $coordinates;

            return;
        }

        foreach ($coordinates as $child) {
            yield from self::coordinateArrays((array) $child);
        }
    }

    /**
     * Coerce the accepted input shapes to a Brick geometry.
     *
     * @param  Geometry|array<mixed>|string  $value
     */
    public static function toGeometry(Geometry|array|string $value): Geometry
    {
        if ($value instanceof Geometry) {
            return $value;
        }

        if (is_array($value)) {
            return (new GeoJsonReader)->read(json_encode($value, JSON_THROW_ON_ERROR));
        }

        $value = trim($value);

        return str_starts_with($value, '{')
            ? (new GeoJsonReader)->read($value)
            : (new WktReader)->read($value, self::SRID);
    }

    /**
     * MySQL hands geometry back in two shapes and they have to be told apart.
     *
     * A plain column read returns MySQL's internal format: a 4-byte
     * little-endian SRID followed by standard WKB. An explicit `ST_AsBinary`
     * select returns bare WKB, which begins with its byte-order flag (0x00 or
     * 0x01) and then a geometry type.
     *
     * Sniffing the SRID prefix alone is not enough: a bare WKB point starts
     * `01 01 00 00 00`, whose first four bytes read as a plausible SRID. So
     * check for bare WKB first, by reading the byte-order flag and the type it
     * implies, and treat everything else as prefixed.
     */
    private static function stripSridPrefix(string $binary): string
    {
        if (strlen($binary) < 5) {
            return $binary;
        }

        $byteOrder = ord($binary[0]);

        if ($byteOrder === 0 || $byteOrder === 1) {
            $type = unpack($byteOrder === 1 ? 'V' : 'N', substr($binary, 1, 4))[1] ?? 0;

            // 1..7 are the OGC types; the range is widened a little for the
            // curve and surface types brick/geo also reads.
            if ($type >= 1 && $type <= 17) {
                return $binary;
            }
        }

        return substr($binary, 4);
    }
}
