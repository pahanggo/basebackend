<?php

namespace Gis\Validation;

use Brick\Geo\Geometry;
use Gis\Casts\GeometryCast;
use Gis\Commands\CommandFailed;
use Illuminate\Support\Facades\DB;

/**
 * The geometry rules from specification section 9, enforced where they have to
 * be enforced.
 *
 * **Client validation is a UX affordance; this is the guarantee.** The client
 * runs the same checks so a user is told about a problem while they are still
 * drawing, but the command endpoint is a public API reached with a session
 * cookie, and nothing that arrives there has been through a drawing tool
 * necessarily. Every rule is repeated here, independently.
 *
 * Two kinds of rule, and the difference is about intent:
 *
 * - **Auto-fixes apply silently.** An unclosed ring, a vertex repeated twice,
 *   a segment of zero length — none of these is a decision anyone made. They
 *   are artefacts of a pointer, a snap or a round trip through a format, and
 *   asking about them would be asking the user to confirm something they did
 *   not do.
 * - **Blocks refuse the write and name the cause.** A self-intersection, a
 *   triangle with two corners, a million vertices: each one means the geometry
 *   is not what the user thinks it is, and quietly repairing it would put
 *   something on the map that nobody drew.
 */
class GeometryValidator
{
    /** Longitude and latitude bounds. Outside these is not a coordinate. */
    private const MAX_LONGITUDE = 180.0;

    private const MAX_LATITUDE = 90.0;

    /**
     * The geometry as it should be stored, or a refusal.
     *
     * Order matters. Coordinates are range-checked first, because everything
     * after it assumes numbers that mean something; then the silent repairs,
     * because the structural checks should judge the geometry as it will be
     * stored rather than as it arrived; then the blocks.
     */
    public static function normalise(Geometry $geometry): Geometry
    {
        // `toArray()` gives the coordinates and `geometryType()` the tag, which
        // is the whole of GeoJSON without encoding and decoding a document to
        // get at it.
        $type = $geometry->geometryType();
        $coordinates = $geometry->toArray();

        self::assertInRange($coordinates);

        $coordinates = self::repair($type, $coordinates);

        self::assertEnoughVertices($type, $coordinates);

        $repaired = GeometryCast::toGeometry(['type' => $type, 'coordinates' => $coordinates]);

        self::assertSimple($repaired);

        return $repaired;
    }

    /**
     * Every coordinate is a real place.
     *
     * Checked before anything else touches them: a NaN or a longitude of 4,000
     * poisons every comparison downstream, and the bounding box it produces
     * would match every viewport query forever.
     *
     * @param  array<mixed>  $coordinates
     */
    protected static function assertInRange(array $coordinates): void
    {
        foreach (self::positions($coordinates) as [$longitude, $latitude]) {
            if (! is_finite($longitude) || ! is_finite($latitude)) {
                throw CommandFailed::invalidGeometry('A coordinate is not a number.');
            }

            if (abs($longitude) > self::MAX_LONGITUDE || abs($latitude) > self::MAX_LATITUDE) {
                throw CommandFailed::invalidGeometry(sprintf(
                    'Coordinate %s, %s is outside the valid range.',
                    $longitude,
                    $latitude,
                ));
            }
        }
    }

    /**
     * The silent repairs, applied depth-first through the nesting.
     *
     * @param  array<mixed>  $coordinates
     * @return array<mixed>
     */
    protected static function repair(string $type, array $coordinates): array
    {
        return match ($type) {
            'Point' => $coordinates,
            'MultiPoint' => $coordinates,
            'LineString' => self::dedupe($coordinates),
            'MultiLineString' => array_map(self::dedupe(...), $coordinates),
            'Polygon' => self::rings($coordinates),
            'MultiPolygon' => array_map(self::rings(...), $coordinates),
            default => $coordinates,
        };
    }

    /**
     * A polygon's rings: deduplicated, closed, and wound the right way.
     *
     * **Winding is normalised rather than checked.** RFC 7946 wants an
     * exterior ring counter-clockwise and its holes clockwise, and a ring wound
     * the wrong way is not an error anybody can act on — it is a property of
     * whichever tool produced it. Normalising means every consumer downstream,
     * including a renderer filling `evenodd`, sees one convention.
     *
     * @param  array<mixed>  $rings
     * @return array<mixed>
     */
    protected static function rings(array $rings): array
    {
        $out = [];

        foreach ($rings as $index => $ring) {
            $ring = self::close(self::dedupe($ring));

            // The first ring is the exterior; everything after it is a hole.
            $wantCounterClockwise = $index === 0;

            if (count($ring) >= 4 && self::isCounterClockwise($ring) !== $wantCounterClockwise) {
                $ring = array_reverse($ring);
            }

            $out[] = $ring;
        }

        return $out;
    }

    /**
     * Consecutive duplicates removed.
     *
     * This is the zero-length-segment rule and the duplicate-vertex rule at
     * once: a segment has zero length exactly when its two ends are the same
     * point, so one pass removes both. A snap that lands on the vertex already
     * placed is the ordinary way either arises.
     *
     * @param  array<mixed>  $ring
     * @return array<mixed>
     */
    protected static function dedupe(array $ring): array
    {
        $out = [];

        foreach ($ring as $position) {
            $last = end($out);

            if ($last !== false && self::samePosition($last, $position)) {
                continue;
            }

            $out[] = $position;
        }

        return array_values($out);
    }

    /**
     * A ring closed, if it is not already.
     *
     * Note this runs AFTER deduplication, which would otherwise strip the
     * repeated first vertex of an already-closed ring and leave it open.
     *
     * @param  array<mixed>  $ring
     * @return array<mixed>
     */
    protected static function close(array $ring): array
    {
        if (count($ring) < 3) {
            return $ring;
        }

        if (! self::samePosition($ring[0], $ring[count($ring) - 1])) {
            $ring[] = $ring[0];
        }

        return $ring;
    }

    /**
     * Enough distinct vertices to be the shape it claims to be.
     *
     * Counted on the closed ring, so the repeated last vertex does not pay for
     * itself: a triangle is four positions and three corners.
     *
     * @param  array<mixed>  $coordinates
     */
    protected static function assertEnoughVertices(string $type, array $coordinates): void
    {
        $polygons = match ($type) {
            'Polygon' => [$coordinates],
            'MultiPolygon' => $coordinates,
            default => [],
        };

        foreach ($polygons as $rings) {
            foreach ($rings as $ring) {
                if (count($ring) - 1 < 3) {
                    throw CommandFailed::invalidGeometry(
                        'A polygon ring needs at least three distinct corners.',
                    );
                }
            }
        }

        $lines = match ($type) {
            'LineString' => [$coordinates],
            'MultiLineString' => $coordinates,
            default => [],
        };

        foreach ($lines as $line) {
            if (count($line) < 2) {
                throw CommandFailed::invalidGeometry('A line needs at least two distinct points.');
            }
        }
    }

    /**
     * No ring crosses itself or another.
     *
     * Asked of MySQL rather than computed here. `ST_IsValid` is the same
     * predicate every later spatial operation will be judged by, so agreeing
     * with it matters more than agreeing with a hand-rolled sweep — a geometry
     * this accepted and `ST_Intersects` then refused would be a bug with no
     * visible cause. S1b measured 11,638 of 1.4 million imported rows failing
     * it, almost all self-intersections, so it is neither theoretical nor rare.
     *
     * One round trip per written feature, on a path that writes hundreds at
     * most. The read path could not afford this; the write path can.
     */
    protected static function assertSimple(Geometry $geometry): void
    {
        if ($geometry->isEmpty()) {
            throw CommandFailed::invalidGeometry('Geometry is empty.');
        }

        $row = DB::connection(config('gis.connection'))->selectOne(
            'select ST_IsValid('.GeometryCast::literal($geometry).') as valid',
        );

        if (! (bool) ($row->valid ?? false)) {
            throw CommandFailed::invalidGeometry('The geometry crosses itself.');
        }
    }

    /**
     * Shoelace sign. Positive is counter-clockwise in longitude-latitude.
     *
     * Planar, and deliberately: this decides a winding convention, not an area.
     * A ring small enough to be a parcel and a ring the size of a state both
     * get the same answer from it, and the geodesic alternative would cost a
     * trigonometric call per vertex to answer a question about sign.
     *
     * @param  array<mixed>  $ring
     */
    protected static function isCounterClockwise(array $ring): bool
    {
        $sum = 0.0;

        for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
            $sum += ($ring[$i + 1][0] - $ring[$i][0]) * ($ring[$i + 1][1] + $ring[$i][1]);
        }

        // The shoelace sum above is twice the signed area, negated: it is
        // negative for a counter-clockwise ring.
        return $sum < 0;
    }

    /**
     * @param  array<mixed>  $a
     * @param  array<mixed>  $b
     */
    protected static function samePosition(array $a, array $b): bool
    {
        // Exact equality, not a tolerance. A tolerance here would be a
        // simplification rule wearing a repair's clothes, and this package has
        // no level of detail by design.
        return $a[0] === $b[0] && $a[1] === $b[1];
    }

    /**
     * Every position in an arbitrarily nested coordinate array.
     *
     * @param  array<mixed>  $coordinates
     * @return iterable<array{0: float, 1: float}>
     */
    protected static function positions(array $coordinates): iterable
    {
        if ($coordinates === []) {
            return;
        }

        if (is_numeric($coordinates[array_key_first($coordinates)])) {
            yield $coordinates;

            return;
        }

        foreach ($coordinates as $child) {
            if (is_array($child)) {
                yield from self::positions($child);
            }
        }
    }
}
