<?php

namespace Gis\Support;

use Gis\Casts\GeometryCast;
use Gis\Models\Layer;
use Illuminate\Support\Facades\DB;

/**
 * The two summary columns a bulk import leaves behind: how many features the
 * layer holds, and the box they occupy.
 *
 * Both are aggregates over `gis_features`, so they are computed once at the end
 * of an import rather than maintained per row — 3.6 million increments of the
 * same column would be the whole cost of the import.
 *
 * `extent` is metadata for zooming to a layer, not something queried against,
 * which is why the column carries no spatial index and why a bounding box is
 * enough. It is built from the stored `minx`/`maxx` columns rather than from
 * the geometry: `ST_Envelope` is not implemented for geographic reference
 * systems, and those columns exist precisely so that ordinary B-tree
 * aggregation can answer this.
 */
final class LayerRollup
{
    /**
     * @return array{count: int, minx: float|null, miny: float|null, maxx: float|null, maxy: float|null}
     */
    public static function apply(Layer $layer): array
    {
        $bounds = DB::connection(config('gis.connection'))->selectOne(
            'SELECT COUNT(*) AS n, MIN(minx) AS minx, MIN(miny) AS miny, MAX(maxx) AS maxx, MAX(maxy) AS maxy
               FROM gis_features WHERE layer_id = ?',
            [$layer->id],
        );

        $layer->feature_count = (int) $bounds->n;

        if ($bounds->n > 0) {
            $layer->extent = GeometryCast::toGeometry(sprintf(
                'POLYGON((%1$F %2$F, %3$F %2$F, %3$F %4$F, %1$F %4$F, %1$F %2$F))',
                $bounds->minx, $bounds->miny, $bounds->maxx, $bounds->maxy,
            ));
        }

        $layer->save();

        return [
            'count' => (int) $bounds->n,
            'minx' => $bounds->n > 0 ? (float) $bounds->minx : null,
            'miny' => $bounds->n > 0 ? (float) $bounds->miny : null,
            'maxx' => $bounds->n > 0 ? (float) $bounds->maxx : null,
            'maxy' => $bounds->n > 0 ? (float) $bounds->maxy : null,
        ];
    }

    /** Human-readable extent, for console output. */
    public static function describe(array $rollup): string
    {
        return $rollup['count'] > 0
            ? sprintf('%.3f, %.3f to %.3f, %.3f', $rollup['minx'], $rollup['miny'], $rollup['maxx'], $rollup['maxy'])
            : 'empty';
    }
}
