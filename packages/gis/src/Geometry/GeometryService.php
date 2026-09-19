<?php

namespace Gis\Geometry;

use Brick\Geo\Geometry;

/**
 * Constructive geometry: the operations that make a new shape out of old ones.
 *
 * **An interface because this is the migration path to PostGIS.** Geometry is
 * stored as standard WKB with identical SRID semantics, so moving later swaps
 * one implementation for another rather than rewriting the application — which
 * only holds if nothing MySQL-specific leaks through here. Everything below
 * takes and returns `Brick\Geo\Geometry` in SRID 4326.
 *
 * **MySQL cannot do any of this.** Its buffer, union, difference, intersection
 * and simplify functions are Cartesian-only: on a geographic reference system
 * they either error or, worse, return a confidently wrong answer in degrees.
 * It has no make-valid function at all. Do not reach for a SQL shortcut in an
 * implementation of this interface (specification §5).
 *
 * Those function names are spelled out nowhere in this package on purpose —
 * an architecture test greps for them, and it is blunt enough that naming one
 * in a comment trips it. That bluntness is the point: the shortcut is tempting
 * and its failure is invisible, because geometry in the wrong place still
 * draws, still indexes and still exports.
 *
 * None of these is on a hot path. They run when a user clicks a button, never
 * per pan, which is what makes a subprocess and two round trips acceptable.
 */
interface GeometryService
{
    /**
     * A buffer of `$metres` around the geometry, geodesic.
     *
     * Negative buffers inward. An inward buffer larger than the shape returns
     * an empty geometry rather than failing — that is a real answer to "shrink
     * this by more than it is wide".
     */
    public function buffer(Geometry $geometry, float $metres): Geometry;

    /** @param array<int, Geometry> $geometries */
    public function union(array $geometries): Geometry;

    public function difference(Geometry $subject, Geometry $cutter): Geometry;

    public function intersection(Geometry $a, Geometry $b): Geometry;

    /** The smallest convex shape containing the geometry. */
    public function convexHull(Geometry $geometry): Geometry;

    /** The centroid, which for an L-shape may fall outside the shape. */
    public function centroid(Geometry $geometry): Geometry;

    /**
     * A point guaranteed to be ON the surface.
     *
     * Different from the centroid and needed wherever a label or a marker must
     * actually sit on the thing it names.
     */
    public function pointOnSurface(Geometry $geometry): Geometry;

    /**
     * Douglas-Peucker, with the tolerance in METRES rather than degrees.
     *
     * Metres because a tolerance in degrees means a different distance at every
     * latitude, and a user asking to simplify to five metres means five metres.
     */
    public function simplify(Geometry $geometry, float $toleranceMetres): Geometry;

    /**
     * Repair a self-intersecting or otherwise invalid geometry.
     *
     * The import found 11,638 invalid rows in 1.4 million, almost all
     * self-intersections, so this is neither theoretical nor rare.
     */
    public function makeValid(Geometry $geometry): Geometry;

    /** Whether this implementation can actually do the work. */
    public function available(): bool;
}
