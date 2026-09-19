<?php

namespace Gis\Http\Controllers\Api;

use Gis\Casts\GeometryCast;
use Gis\Http\Encoders\BinaryFeatureEncoder;
use Gis\Models\Layer;
use Gis\Support\Classification;
use Gis\Support\ViewportRead;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;


class FeatureReadController extends Controller
{
    public const GEOJSON_TYPE = 'application/geo+json';

    /**
     * The binary encoding is a **stream of framed `GIS1` documents**, not one
     * document, so it carries its own media type: a reader that expected the
     * old single-document form would otherwise read a frame header as a GIS1
     * header and fail obscurely instead of on negotiation.
     */
    public const BINARY_TYPE = 'application/vnd.gis.features+gis1-stream';

    /** A frame carrying one `GIS1` document of at most `stream_chunk` features. */
    public const FRAME_FEATURES = 1;

    /** The last frame: the `cull` object, as JSON. Ends the stream. */
    public const FRAME_TRAILER = 2;


    /**
     * Features in a viewport, in whichever encoding was negotiated.
     *
     * **Both encodings stream, `stream_chunk` features at a time.** A zoom-12
     * viewport with the cull suppressed is 164,000 features and about 50 MB of
     * JSON, and building that in memory before sending it would defeat the
     * point of asking for it. The binary encoding streams as a sequence of
     * framed `GIS1` documents rather than one, because one cannot: see
     * `streamFrames()`.
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

        // `classify=<attribute>` adds one byte per feature naming the class it
        // falls in, so the client can split a layer into sublayers without ever
        // asking for `properties` — which for this data is more than half the
        // payload. Measured on the land-use layer at zoom 12: 22.68 MB and
        // 543 ms without it, 23.52 MB and 534 ms with, against 50.56 MB and
        // 591 ms for the whole attribute document.
        //
        // The field is checked against the layer's `attr_schema`, which is what
        // keeps an arbitrary JSON path out of the query.
        $classify = null;

        if ($request->query('classify') !== null && $request->query('classify') !== '') {
            try {
                $classify = Classification::assertField($layer, $request->query('classify'));
            } catch (InvalidArgumentException $e) {
                abort(422, $e->getMessage());
            }
        }

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

        if ($classify !== null) {
            // Bound, not interpolated: `JSON_EXTRACT` takes a placeholder for
            // its path, unlike the geometry constructor in `GeometryCast`.
            // Select bindings precede the where clause's, and the raw `from`
            // carries none, so the order holds.
            $rows->selectRaw(
                'JSON_UNQUOTE(JSON_EXTRACT(properties, ?)) as class_value',
                [Classification::path($classify)],
            );
        }

        return response()->stream(
            fn () => $binary
                ? $this->streamFrames($rows, $viewport, $threshold, $withProperties, $cap, $classify !== null)
                : $this->streamCollection($rows, $viewport, $threshold, $withProperties, $cap, $classify !== null),
            200,
            [
                'Content-Type' => $binary ? self::BINARY_TYPE : self::GEOJSON_TYPE,
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
     * Write the binary encoding as a stream of framed `GIS1` documents.
     *
     * A single `GIS1` document cannot stream: its header carries the offset of
     * every section, and those are only known once the last feature has been
     * encoded. So the response is a sequence of small documents instead —
     * `stream_chunk` features each, every one complete and self-describing —
     * and the client paints each as it lands rather than waiting for the whole
     * read. Because the rows arrive biggest-first, the first frame carries the
     * most visible features on the screen.
     *
     * Framing is a kind byte and a little-endian length, so a reader knows how
     * much to buffer before it has anything to parse, and a frame it does not
     * recognise is skippable rather than fatal:
     *
     *     uint8   kind: 1 features, 2 trailer
     *     uint32  payload length
     *     bytes   payload
     *
     * The trailer carries the `cull` object and ends the stream. It cannot be
     * a header any more — headers are written before the first feature is
     * read, and `returned`, `capped` and `smallestReturnedM2` are only known
     * at the end. That is the same reason GeoJSON puts its counts last.
     */
    protected function streamFrames(
        Builder $rows,
        ViewportRead $viewport,
        float $threshold,
        bool $withProperties,
        int $cap,
        bool $classified = false,
    ): void {
        $size = max(1, (int) config('gis.read.stream_chunk'));
        $encoder = $this->encoder($viewport, $classified);
        $returned = 0;
        $capped = false;
        $smallest = null;

        foreach ($rows->cursor() as $row) {
            if ($returned === $cap) {
                $capped = true;
                break;
            }

            $encoder->add(
                $row->geometry,
                (float) $row->id,
                (float) $row->area_m2,
                [(float) $row->minx, (float) $row->miny, (float) $row->maxx, (float) $row->maxy],
                $withProperties ? json_decode($row->properties ?: '{}', true) : null,
                $classified ? ($row->class_value ?? null) : null,
            );

            $returned++;
            $smallest = (float) $row->area_m2;

            if ($encoder->count() === $size) {
                $this->frame(self::FRAME_FEATURES, $encoder->encode());
                $encoder = $this->encoder($viewport, $classified);
            }
        }

        // The remainder. An empty frame is never written: a frame declares a
        // feature count, and a reader should not have to special-case zero.
        if ($encoder->count() > 0) {
            $this->frame(self::FRAME_FEATURES, $encoder->encode());
        }

        $this->frame(self::FRAME_TRAILER, json_encode(
            $this->cull($viewport, $threshold, $returned, $smallest, $capped, $cap),
        ));
    }

    /** One framed payload, pushed to the client immediately. */
    protected function frame(int $kind, string $payload): void
    {
        echo chr($kind), pack('V', strlen($payload)), $payload;

        $this->flush();
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
    }

    /**
     * The encoder for this read, and whether it shortens coordinates.
     *
     * Quantisation is applied **below the editing zoom only**. At 1e-7 degrees
     * a coordinate is accurate to about half a centimetre, which is invisible
     * on screen but is not nothing if it travels: above `edit_min_zoom` the
     * client may drag a vertex and send that coordinate back, and a read that
     * had rounded it would write the rounding into storage. Each edit would
     * move the vertex again. Full precision where geometry can round-trip,
     * shortened where it can only be looked at.
     */
    protected function encoder(ViewportRead $viewport, bool $classified = false): BinaryFeatureEncoder
    {
        $exponent = (int) config('gis.read.coord_exponent');

        $encoder = new BinaryFeatureEncoder(
            $exponent > 0 && $viewport->belowEditingZoom() ? $exponent : null,
        );

        if ($classified) {
            $encoder->classify();
        }

        return $encoder;
    }

    /**
     * Which geometry the read returns: the stored one, at every zoom.
     *
     * There is no level-of-detail geometry any more. A feature is returned with
     * the vertices it was imported with, or it is not returned at all — the
     * area cull decides which, and it drops whole features rather than
     * reshaping them.
     */
    protected function geometryColumn(ViewportRead $viewport): string
    {
        return GeometryCast::selectGeoJson('geom', 'geometry');
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
     *
     * The index is named rather than left to the planner — see
     * `ix_layer_read`'s migration for why the read needs that shape, and
     * `forceReadIndex()` for why MySQL will not choose it.
     */
    protected function query(Layer $layer, ViewportRead $viewport): Builder
    {
        $rows = DB::connection(config('gis.connection'))
            ->table(DB::raw('`gis_features` FORCE INDEX (ix_layer_read)'))
            ->where('layer_id', $layer->id)
            ->where('minx', '<=', $viewport->maxx)
            ->where('maxx', '>=', $viewport->minx)
            ->where('miny', '<=', $viewport->maxy)
            ->where('maxy', '>=', $viewport->miny)
            ;

        // Everything the caller already holds, left out. All four columns are
        // in `ix_layer_read`, so this is resolved in the index alongside the
        // viewport test rather than costing a row read to reject a row.
        if ($viewport->exclude !== null) {
            $held = $viewport->exclude;

            $rows->whereNot(fn (Builder $q) => $q
                ->where('minx', '<=', $held->maxx)
                ->where('maxx', '>=', $held->minx)
                ->where('miny', '<=', $held->maxy)
                ->where('maxy', '>=', $held->miny));
        }

        return $rows;
    }

    /**
     * Why the index is forced, and why the old heuristic is gone.
     *
     * `ix_layer_read` is `(layer_id, area_m2, minx, maxx, miny, maxy)`. Read
     * backwards it returns rows already ordered by area, so there is no
     * filesort and the first row is available immediately — which is what lets
     * the response actually stream rather than arrive in one burst at the end.
     * The four bounding-box columns ride along so index condition pushdown
     * rejects 97% of the entries before any row is read; only a feature that
     * survives the viewport test costs a lookup for its geometry.
     *
     * **MySQL will not choose it.** The moment `geom` joins the select list the
     * index stops covering, and the optimiser — which has no statistics on
     * these correlated columns and cannot see that pushdown will discard almost
     * everything — falls back to a table scan and a filesort. Measured on
     * `Gunatanah`, 16,475 features at zoom 12: planner 395 ms with the first
     * row at 368 ms, this index forced 96 ms with the first row at 0.4 ms.
     *
     * This replaces the old `shouldForceBoundingBoxIndex()`, which chose
     * between `ix_layer_area` and `ix_layer_bbox` from the viewport's share of
     * the layer extent. That heuristic existed because neither index served
     * both filters, and it had to be re-tuned whenever `min_area_px` moved. One
     * index now serves both, and it wins or ties at every zoom measured (12,
     * 14, 16, 18) — `ix_layer_bbox` is the only plan that still filesorts, and
     * at zoom 18 its 17 ms of total time costs 69 ms of first-row latency.
     */

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
        bool $classified = false,
    ): void {
        echo '{"type":"FeatureCollection","features":[';

        $chunk = max(1, (int) config('gis.read.stream_chunk'));
        $returned = 0;
        $capped = false;
        $smallest = null;

        foreach ($rows->cursor() as $row) {
            echo $returned === 0 ? '' : ',';
            echo '{"type":"Feature","id":'.$row->id
                .',"geometry":'.$row->geometry
                .',"properties":{"_area":'.$row->area_m2
                .($classified ? ',"_class":'.json_encode($row->class_value, JSON_UNESCAPED_UNICODE) : '')
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
            // worker sits idle. The interval matches the binary encoding's
            // frame size, so both push at the same granularity.
            if ($returned % $chunk === 0) {
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
