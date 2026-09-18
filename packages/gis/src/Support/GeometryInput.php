<?php

namespace Gis\Support;

use Brick\Geo\Exception\GeometryException;
use Brick\Geo\Geometry;
use Gis\Casts\GeometryCast;
use Gis\Commands\CommandFailed;
use InvalidArgumentException;

/**
 * Turns the geometry field of a command into a geometry, with the limits the
 * write path has to enforce before anything reaches the database.
 *
 * Bounded first, parsed second: a malformed 40 MB WKB string should be refused
 * by its length, not by whatever the parser does with it.
 */
class GeometryInput
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function parse(array $payload, string $field = 'geom'): Geometry
    {
        $value = $payload[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw CommandFailed::invalidGeometry("The {$field} field must be a string.");
        }

        $maxBytes = (int) config('gis.write.max_wkb_bytes');

        if (strlen($value) > $maxBytes) {
            throw CommandFailed::invalidGeometry("Geometry exceeds {$maxBytes} bytes.");
        }

        $encoding = $payload['geomEncoding'] ?? 'wkb';

        try {
            $geometry = GeometryCast::fromWire($value, is_string($encoding) ? $encoding : 'wkb');
        } catch (GeometryException|InvalidArgumentException $e) {
            throw CommandFailed::invalidGeometry($e->getMessage());
        }

        $vertices = GeometryCast::countVertices($geometry);
        $limit = (int) config('gis.write.max_vertices_per_feature');

        if ($vertices > $limit) {
            throw CommandFailed::vertexLimit($vertices, $limit);
        }

        if ($geometry->isEmpty()) {
            throw CommandFailed::invalidGeometry('Geometry is empty.');
        }

        return $geometry;
    }

    /**
     * The bounding box columns, computed here rather than by MySQL.
     *
     * `ST_Envelope` is not implemented for geographic reference systems, so the
     * import gets these by reinterpreting the geometry as SRID 0. On the write
     * path the geometry is already parsed in PHP, so `getBoundingBox()` is both
     * simpler and one fewer round trip.
     *
     * @return array{minx: float, miny: float, maxx: float, maxy: float}
     */
    public static function boundingBox(Geometry $geometry): array
    {
        $box = $geometry->getBoundingBox();

        return [
            'minx' => $box->getSouthWest()->x(),
            'miny' => $box->getSouthWest()->y(),
            'maxx' => $box->getNorthEast()->x(),
            'maxy' => $box->getNorthEast()->y(),
        ];
    }
}
