<?php

namespace Gis\Geometry;

use Brick\Geo\Geometry;
use Gis\Casts\GeometryCast;
use RuntimeException;

/**
 * Constructive geometry through GEOS, in metres.
 *
 * Every operation that has a distance in it follows the same three steps, and
 * the reason is the one fact this whole session turns on: **GEOS treats
 * coordinates as unitless Cartesian numbers.**
 *
 * ```
 * ST_Transform(geom, <metric SRID>)   MySQL, 4326 -> metres
 *   -> geosop bufferQuadSegs <d> 32   GEOS, metres in and out
 *   -> ST_Transform(result, 4326)     MySQL, back to storage
 * ```
 *
 * Hand GEOS longitude and latitude with a distance in metres and it answers in
 * degrees without complaint — a 250 m buffer comes back spanning 353 degrees of
 * longitude. That is worse than an error, because an error stops.
 *
 * The zone is chosen per geometry from its centroid and never hardcoded: the
 * data extent straddles 102 degrees east, so a constant would put half the
 * state in the wrong zone and the symptom would only be distances that are
 * quietly a little wrong.
 *
 * Operations with no distance in them — union, difference, intersection, hull,
 * centroid — are projected too. They do not strictly need metres, but a
 * predicate evaluated in degrees and one evaluated in metres disagree about
 * which side of a line a point falls on near the boundary, and having one
 * answer is worth more than saving a round trip on a path nobody is waiting on.
 */
class GeosGeometryService implements GeometryService
{
    public function __construct(private readonly GeosOp $geos) {}

    public function available(): bool
    {
        return $this->geos->available();
    }

    public function buffer(Geometry $geometry, float $metres): Geometry
    {
        // 32 quadrant segments, never the geosop default of 8. Measured on a
        // 250 m buffer at Kuantan: 8 gives -0.647% area error, which breaches
        // the 0.1% budget in section 11; 32 gives -0.046%.
        $segments = (int) config('gis.geometry.buffer_quad_segs');

        return $this->inMetres($geometry, fn (Geometry $projected) => $this->geos->run(
            'bufferQuadSegs',
            $projected,
            null,
            [$metres, $segments],
        ));
    }

    /** @param array<int, Geometry> $geometries */
    public function union(array $geometries): Geometry
    {
        if ($geometries === []) {
            throw new RuntimeException('Nothing to union.');
        }

        $result = array_shift($geometries);

        // Folded pairwise rather than collected into one `unaryUnion` call:
        // the pairwise form is what the interface's two-geometry shape gives,
        // and the inputs here are a user's selection rather than a table.
        foreach ($geometries as $next) {
            $result = $this->pair($result, $next, 'union');
        }

        return $result;
    }

    public function difference(Geometry $subject, Geometry $cutter): Geometry
    {
        return $this->pair($subject, $cutter, 'difference');
    }

    public function intersection(Geometry $a, Geometry $b): Geometry
    {
        return $this->pair($a, $b, 'intersection');
    }

    public function convexHull(Geometry $geometry): Geometry
    {
        return $this->inMetres($geometry, fn (Geometry $g) => $this->geos->run('convexHull', $g));
    }

    public function centroid(Geometry $geometry): Geometry
    {
        return $this->inMetres($geometry, fn (Geometry $g) => $this->geos->run('centroid', $g));
    }

    public function pointOnSurface(Geometry $geometry): Geometry
    {
        // `interiorPoint` is geosop's name for it. Unlike the centroid it is
        // guaranteed to fall ON the shape, which is what a label needs.
        return $this->inMetres($geometry, fn (Geometry $g) => $this->geos->run('interiorPoint', $g));
    }

    public function simplify(Geometry $geometry, float $toleranceMetres): Geometry
    {
        // Topology-preserving, so simplifying cannot turn a valid polygon into
        // a self-intersecting one — which plain Douglas-Peucker will do, and
        // which would then be refused by the validator on the way back in.
        return $this->inMetres($geometry, fn (Geometry $g) => $this->geos->run(
            'simplifyTP',
            $g,
            null,
            [$toleranceMetres],
        ));
    }

    public function makeValid(Geometry $geometry): Geometry
    {
        return $this->inMetres($geometry, fn (Geometry $g) => $this->geos->run('makeValid', $g));
    }

    /**
     * Run a two-geometry operation with both sides in the SAME zone.
     *
     * The same zone is the point. Projecting each side by its own centroid
     * would put them in different coordinate systems, and GEOS — which does
     * not look at the SRID — would intersect them as though they were in one.
     * The result would be geometry in no reference system at all.
     */
    protected function pair(Geometry $a, Geometry $b, string $operation): Geometry
    {
        $srid = MetricSrid::forGeometry($a);

        $projected = $this->geos->run(
            $operation,
            GeometryCast::transform($a, $srid),
            GeometryCast::transform($b, $srid),
        );

        return GeometryCast::toGeographic($projected, $srid);
    }

    /**
     * Project to metres, do the work, and project back.
     *
     * @param  callable(Geometry): Geometry  $work
     */
    protected function inMetres(Geometry $geometry, callable $work): Geometry
    {
        $srid = MetricSrid::forGeometry($geometry);
        $result = $work(GeometryCast::transform($geometry, $srid));

        if ($result->isEmpty()) {
            // An inward buffer larger than the shape is a real answer to a real
            // question, and transforming an empty geometry is not.
            return $result;
        }

        return GeometryCast::toGeographic($result, $srid);
    }
}
