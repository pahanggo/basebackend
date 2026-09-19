<?php

namespace Gis\Geometry;

use Brick\Geo\Geometry;

/**
 * Which projected reference system a geometry should be measured in.
 *
 * **GEOS treats coordinates as unitless Cartesian numbers.** Buffering
 * `POINT(103.326 3.8077)` by 250 returns a polygon spanning 353 degrees of
 * longitude — it read 250 as degrees. Handing it longitude and latitude with a
 * distance in metres produces a confidently wrong answer, which is worse than
 * a refusal, so every constructive operation is projected to metres first.
 *
 * **The zone cannot be hardcoded.** The data extent runs 101.33 to 104.21
 * degrees east, which straddles the 102 degree boundary between UTM 47N and
 * 48N. A constant would put half the state in the wrong zone, and the error is
 * a smooth one — distances would simply be a little wrong, with nothing to
 * notice.
 */
final class MetricSrid
{
    /**
     * The zone for a geometry, from its centroid's longitude.
     *
     * The centroid rather than a corner, so a shape spanning the boundary lands
     * in the zone most of it is in — and deterministically, because the same
     * geometry always produces the same centroid. A shape straddling the
     * boundary is measured in one zone throughout rather than in two, which is
     * the only way its parts stay comparable with each other.
     */
    public static function forGeometry(Geometry $geometry): int
    {
        $box = $geometry->getBoundingBox();
        $longitude = ($box->getSouthWest()->x() + $box->getNorthEast()->x()) / 2;

        return self::forLongitude($longitude);
    }

    public static function forLongitude(float $longitude): int
    {
        $split = (float) config('gis.geometry.metric_srid.split_longitude');

        return $longitude < $split
            ? (int) config('gis.geometry.metric_srid.west')
            : (int) config('gis.geometry.metric_srid.east');
    }
}
