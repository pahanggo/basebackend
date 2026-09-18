<?php

namespace Gis\Http\Controllers\Api;

use Gis\Casts\GeometryCast;
use Gis\Http\Encoders\BinaryFeatureEncoder;
use Gis\Models\Layer;
use Gis\Support\ViewportRead;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class FeatureReadController extends Controller
{
    public const GEOJSON_TYPE = 'application/geo+json';

    public const BINARY_TYPE = 'application/vnd.gis.features+gis1';


    /**
     * Features in a viewport, as GeoJSON.
     *
     * S3 adds the `GIS1` binary encoding, content negotiation and keyset
     * paging at this same URL; this is the GeoJSON half of that contract,
     * which the renderer's worker parses into typed arrays.
     *
     * The response streams. A zoom-12 viewport with the cull suppressed is
     * 164,000 features and about 50 MB of JSON, and building that in memory
     * before sending it would defeat the point of asking for it.
     */
    public function __invoke(Request $request, Layer $layer): SymfonyResponse
    {
        try {
            $viewport = ViewportRead::fromQuery($request->query());
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $threshold = $viewport->areaThreshold();
        $withProperties = $request->boolean('fields');

        $cap = (int) config('gis.read.max_features_per_response');

        // Biggest first, capped. The area threshold alone does not hold the
        // response to a size: it is a constant in square pixels, so a larger
        // window simply gets more features — S1b measured a 1456x840 viewport
        // taking 3.6x the intended count. Ordering by area and capping makes
        // the count the thing that is bounded, and what falls off the end is
        // always the least visible thing on the screen.
        $binary = $this->wantsBinary($request);

        $rows = $this->query($layer, $viewport)
            ->where('area_m2', '>=', $threshold)
            ->select($this->columns($viewport, $withProperties, $binary))
            ->orderByDesc('area_m2')
            ->limit($cap + 1);

        if ($binary) {
            return $this->binaryResponse($rows, $viewport, $threshold, $withProperties, $cap);
        }

        return response()->stream(
            fn () => $this->streamCollection($rows, $viewport, $threshold, $withProperties, $cap),
            200,
            [
                'Content-Type' => self::GEOJSON_TYPE,
                'X-Gis-Area-Threshold' => (string) round($threshold, 4),
            ],
        );
    }

    /**
     * Which encoding the caller gets.
     *
     * Content negotiation, never a `?format=` parameter: the two encodings are
     * the same resource. The renderer asks for `GIS1`; anything that does not
     * ask for it — a browser address bar, curl, a debugging session — gets
     * GeoJSON, which is the readable one.
     *
     * The specification's "binary above 2,000 features" rule lives in the
     * client, which is where the knowledge is: deciding it here would mean
     * counting the result before encoding it, and S1b measured that count at
     * two thirds of the request.
     */
    protected function wantsBinary(Request $request): bool
    {
        $accept = (string) $request->header('Accept', '');

        return str_contains($accept, self::BINARY_TYPE);
    }

    /**
     * The whole response in memory, on purpose.
     *
     * A capped read is 20,000 features, which is about 2.5 MB encoded — small
     * enough that streaming would buy nothing and cost the ability to write the
     * header, whose section offsets are only known once every section has been
     * built.
     */
    protected function binaryResponse(
        Builder $rows,
        ViewportRead $viewport,
        float $threshold,
        bool $withProperties,
        int $cap,
    ): Response {
        $encoder = new BinaryFeatureEncoder;
        $capped = false;
        $smallest = null;

        foreach ($rows->cursor() as $row) {
            if ($encoder->count() === $cap) {
                $capped = true;
                break;
            }

            $encoder->add(
                $row->geometry,
                (float) $row->id,
                (float) $row->area_m2,
                [(float) $row->minx, (float) $row->miny, (float) $row->maxx, (float) $row->maxy],
                $withProperties ? json_decode($row->properties ?: '{}', true) : null,
            );

            $smallest = (float) $row->area_m2;
        }

        return response($encoder->encode(), 200, [
            'Content-Type' => self::BINARY_TYPE,
            'X-Gis-Cull' => json_encode($this->cull($viewport, $threshold, $encoder->count(), $smallest, $capped, $cap)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function cull(
        ViewportRead $viewport,
        float $threshold,
        int $returned,
        ?float $smallest,
        bool $capped,
        int $cap,
    ): array {
        return [
            'returned' => $returned,
            'areaThresholdM2' => round($threshold, 4),
            'smallestReturnedM2' => $smallest === null ? null : round($smallest, 4),
            'capped' => $capped,
            'cap' => $cap,
            'zoom' => $viewport->zoom,
            'simplified' => $viewport->usesSimplifiedGeometry(),
        ];
    }

    /**
     * What each row carries.
     *
     * Attributes are **not** sent by default. The renderer needs geometry, an
     * id and the area it was culled on, and nothing else; for the imported
     * cadastre the attribute document is most of the payload. `fields=1` adds
     * it back for the attribute table.
     *
     * @return array<int, mixed>
     */
    protected function columns(ViewportRead $viewport, bool $withProperties, bool $binary = false): array
    {
        $columns = ['id', 'area_m2'];

        if ($binary) {
            // The bounding box is stored, so the encoder never computes one.
            $columns = array_merge($columns, ['minx', 'miny', 'maxx', 'maxy']);
        }

        $columns[] = $binary
            ? $this->binaryGeometryColumn($viewport)
            : DB::raw($this->geometryColumn($viewport));

        if ($withProperties) {
            $columns[] = 'properties';
        }

        return $columns;
    }

    /** WKB, whose coordinate runs the encoder copies out byte for byte. */
    protected function binaryGeometryColumn(ViewportRead $viewport): Expression
    {
        return GeometryCast::selectBinary('geom', 'geometry');
        return $viewport->usesSimplifiedGeometry()
            ? GeometryCast::selectBinary('geom_simple', 'geometry', fallback: 'geom')
            : GeometryCast::selectBinary('geom', 'geometry');
    }

    /**
     * Which geometry the read returns.
     *
     * Below the editing zoom the pre-simplified column is enough, and it is
     * what `gis:import-bencana` filled for the features large enough to still
     * be drawn when zoomed out. Where it is NULL the feature was too small to
     * be worth simplifying, so the original is returned.
     */
    protected function geometryColumn(ViewportRead $viewport): string
    {
        return $viewport->usesSimplifiedGeometry()
            ? GeometryCast::selectGeoJson('geom_simple', 'geometry', fallback: 'geom')
            : GeometryCast::selectGeoJson('geom', 'geometry');
    }

    /**
     * The viewport query.
     *
     * Deliberately **not** `ST_Intersects`. S1b measured both against the real
     * 1.4 million rows: the redundant bbox columns plus `area_m2` return the
     * identical features in 0.48 s where the geodesic predicate takes 1.69 s,
     * because four double comparisons are not a spherical geometry problem.
     * The exact test is the client's job, and it re-culls against its real
     * viewport anyway.
     */
    protected function query(Layer $layer, ViewportRead $viewport): Builder
    {
        $table = $this->shouldForceBoundingBoxIndex($layer, $viewport)
            ? '`gis_features` FORCE INDEX (ix_layer_bbox)'
            : '`gis_features`';

        return DB::connection(config('gis.connection'))
            ->table(DB::raw($table))
            ->where('layer_id', $layer->id)
            ->where('minx', '<=', $viewport->maxx)
            ->where('maxx', '>=', $viewport->minx)
            ->where('miny', '<=', $viewport->maxy)
            ->where('maxy', '>=', $viewport->miny);
    }

    /**
     * Which index this read should use, because the planner gets it wrong at
     * exactly the zoom people work at.
     *
     * `ORDER BY area_m2 DESC LIMIT n` invites the planner to read
     * `ix_layer_area` backwards and stop once it has n rows. That is a good
     * plan when the area threshold is selective and a terrible one when it is
     * not: at zoom 16 the threshold admits 385,510 of the layer's 672,109
     * features, so the scan walks most of them looking for the few thousand
     * that also fall in a 3 km viewport. Measured on `lots` at
     * `min_area_px = 100`, per layer:
     *
     *     zoom 16   planner 1.10 s   forcing ix_layer_bbox 0.14 s
     *     zoom 14   planner 0.54 s   forcing ix_layer_bbox 0.26 s
     *     zoom 12   planner 0.03 s   forcing ix_layer_bbox 0.38 s
     *
     * The plans trade places on one thing: whether the viewport or the area
     * threshold is the more selective filter. Zoomed out the threshold is
     * enormous and few features pass it, so the area index is nearly free;
     * zoomed in the viewport is a pinprick and the bounding box is what
     * narrows the search.
     *
     * MySQL cannot see this — it estimates 770,775 rows for either plan,
     * because it has no statistics on these correlated columns — so the choice
     * is made here, from the viewport's share of the layer's extent.
     *
     * **The crossover moves with `min_area_px`, so the two are tuned
     * together.** At 100 px² it sits between 0.4% and 5.9%; at 4 px² it sits
     * between 5.9% and 100%. If this becomes a nuisance, the principled fix is
     * to store an area distribution per layer at import and estimate both row
     * counts properly, rather than inferring one of them from geometry.
     */
    protected function shouldForceBoundingBoxIndex(Layer $layer, ViewportRead $viewport): bool
    {
        $extent = $layer->extent;

        if ($extent === null) {
            return false;
        }

        $box = $extent->getBoundingBox();

        if ($box->isEmpty()) {
            return false;
        }

        $extentArea = ($box->getNorthEast()->x() - $box->getSouthWest()->x())
            * ($box->getNorthEast()->y() - $box->getSouthWest()->y());

        if ($extentArea <= 0.0) {
            return false;
        }

        $viewportArea = ($viewport->maxx - $viewport->minx) * ($viewport->maxy - $viewport->miny);

        return $viewportArea / $extentArea < (float) config('gis.read.bbox_index_max_share');
    }

    /**
     * Write the FeatureCollection a row at a time.
     *
     * The geometry and the attribute document both arrive from MySQL as JSON
     * text, so they are written through rather than decoded and re-encoded.
     *
     * The counts come last, after the features, because knowing them up front
     * would mean two `COUNT(*)` queries over the same 174,000 candidates — and
     * those measured at two thirds of the whole request. A reader that wants
     * them can read to the end; the renderer does not need them at all.
     */
    protected function streamCollection(
        Builder $rows,
        ViewportRead $viewport,
        float $threshold,
        bool $withProperties,
        int $cap,
    ): void {
        echo '{"type":"FeatureCollection","features":[';

        $returned = 0;
        $capped = false;
        $smallest = null;

        foreach ($rows->cursor() as $row) {
            echo $returned === 0 ? '' : ',';
            echo '{"type":"Feature","id":'.$row->id
                .',"geometry":'.$row->geometry
                .',"properties":{"_area":'.$row->area_m2
                .($withProperties ? ',"attributes":'.($row->properties ?: '{}') : '')
                .'}}';
            $returned++;
            $smallest = (float) $row->area_m2;

            if ($returned === $cap) {
                $capped = true;
                break;
            }

            // Without this the response is not streamed at all: PHP's output
            // buffer holds the whole body, which for a padded zoom-12 read is
            // tens of megabytes, and the client waits for all of it while the
            // worker sits idle.
            if ($returned % 2000 === 0) {
                $this->flush();
            }
        }

        echo '],"cull":'.json_encode($this->cull($viewport, $threshold, $returned, $smallest, $capped, $cap)).'}';

        $this->flush();
    }

    /** Push what has been written so far to the client. */
    protected function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
