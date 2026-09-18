<?php

namespace Gis\Casts;

use Brick\Geo\Geometry;
use Brick\Geo\Io\GeoJsonReader;
use Brick\Geo\Io\WkbReader;
use Brick\Geo\Io\WktReader;
use Brick\Geo\Io\WktWriter;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Query\Expression;
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
    public static function selectBinary(string $column, ?string $alias = null): Expression
    {
        return new Expression(sprintf(
            "ST_AsBinary(`%s`, '%s') as `%s`",
            $column,
            self::AXIS_ORDER,
            $alias ?? $column,
        ));
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
