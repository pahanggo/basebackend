<?php

namespace Gis\Geometry;

use Brick\Geo\Geometry;
use RuntimeException;

/**
 * What runs where GEOS is not installed: a clear refusal, never a guess.
 *
 * **There is no pure-PHP fallback and there should not be one.** `brick/geo`
 * has no pure-PHP engine, and writing one would mean a second implementation of
 * buffering and overlay that agrees with GEOS to within the 0.1% section 11
 * requires — which is a geometry library, not a fallback. A wrong parcel
 * boundary that looks right is the failure this package can least afford.
 *
 * So the session's gate is met the other way: "with GEOS unavailable, the
 * fallback either produces a correct result or refuses with a clear message —
 * never a silently degraded one." This refuses, and says which binary is
 * missing and where it was looked for.
 *
 * The client learns the same thing from `capabilities.geos` in the bootstrap
 * and does not offer the operations at all, so this message is for a request
 * that ignored that — or for a deployment that lost the binary after the page
 * was loaded.
 */
class UnavailableGeometryService implements GeometryService
{
    public function available(): bool
    {
        return false;
    }

    public function buffer(Geometry $geometry, float $metres): Geometry
    {
        $this->refuse('buffer');
    }

    /** @param array<int, Geometry> $geometries */
    public function union(array $geometries): Geometry
    {
        $this->refuse('union');
    }

    public function difference(Geometry $subject, Geometry $cutter): Geometry
    {
        $this->refuse('difference');
    }

    public function intersection(Geometry $a, Geometry $b): Geometry
    {
        $this->refuse('intersection');
    }

    public function convexHull(Geometry $geometry): Geometry
    {
        $this->refuse('convex hull');
    }

    public function centroid(Geometry $geometry): Geometry
    {
        $this->refuse('centroid');
    }

    public function pointOnSurface(Geometry $geometry): Geometry
    {
        $this->refuse('point on surface');
    }

    public function simplify(Geometry $geometry, float $toleranceMetres): Geometry
    {
        $this->refuse('simplify');
    }

    public function makeValid(Geometry $geometry): Geometry
    {
        $this->refuse('make valid');
    }

    /**
     * @return never
     */
    protected function refuse(string $operation): Geometry
    {
        throw new RuntimeException(sprintf(
            'Cannot %s: the GEOS binary is not available at %s. Install geos and set GIS_GEOSOP_PATH.',
            $operation,
            config('gis.geometry.geosop') ?: '(no path configured)',
        ));
    }
}
