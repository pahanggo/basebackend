<?php

namespace Gis\Models;

use Brick\Geo\Geometry;
use Gis\Casts\GeometryCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $layer_id
 * @property Geometry|null $geom
 * @property float $area_m2
 * @property int $vertex_count
 * @property array<string, mixed> $properties
 * @property int $version
 */
class Feature extends GisModel
{
    use HasGisFactory;

    protected $table = 'gis_features';

    protected $fillable = [
        'layer_id', 'geom',
        'minx', 'miny', 'maxx', 'maxy',
        'area_m2', 'vertex_count', 'properties', 'version',
    ];

    protected $casts = [
        'layer_id' => 'integer',
        'geom' => GeometryCast::class,
        'minx' => 'float',
        'miny' => 'float',
        'maxx' => 'float',
        'maxy' => 'float',
        'area_m2' => 'float',
        'vertex_count' => 'integer',
        'properties' => 'array',
        'version' => 'integer',
    ];

    public function layer(): BelongsTo
    {
        return $this->belongsTo(Layer::class, 'layer_id');
    }

    /**
     * Features whose geometry intersects the given bounding box.
     *
     * This is the query the spatial index exists for, so it is written once
     * here rather than assembled per caller — and the test suite asserts with
     * `EXPLAIN` that MySQL actually picks `sx_geom` rather than scanning.
     */
    public function scopeInBbox(
        Builder $query,
        float $minx,
        float $miny,
        float $maxx,
        float $maxy,
    ): Builder {
        $envelope = GeometryCast::literal(
            GeometryCast::toGeometry(sprintf(
                'POLYGON((%1$F %2$F, %3$F %2$F, %3$F %4$F, %1$F %4$F, %1$F %2$F))',
                $minx, $miny, $maxx, $maxy,
            )),
        );

        return $query->whereRaw("ST_Intersects(`geom`, {$envelope})");
    }

    /**
     * The area cull: at a given zoom, drop anything that would paint smaller
     * than `gis.read.min_area_px`. This is the mechanism that holds the drawn
     * set near 13,000 features at every zoom, and it belongs on the server —
     * shipping 160,000 features for the client to discard would blow the
     * transfer budget (specification section 4).
     */
    public function scopeAreaCulled(Builder $query, int $zoom, float $latitude): Builder
    {
        return $query->where('area_m2', '>=', self::minimumArea($zoom, $latitude));
    }

    /**
     * Square metres per `min_area_px` square pixels at this zoom and latitude.
     */
    public static function minimumArea(int $zoom, float $latitude): float
    {
        // Web Mercator ground resolution: 156543.03392 m/px at the equator,
        // halved per zoom level, narrowed by the cosine of the latitude.
        $metresPerPixel = 156543.03392 * cos(deg2rad($latitude)) / (2 ** $zoom);

        return $metresPerPixel ** 2 * (float) config('gis.read.min_area_px');
    }
}
