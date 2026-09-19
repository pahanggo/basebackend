<?php

namespace Gis\Http\Controllers\Api;

use Gis\Models\Layer;
use Gis\Support\Classification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The distinct values of one property, so a layer can be split by it.
 *
 * ```
 * GET /api/geo/layers/2/values?field=gunatanah_kategori
 * → { "field": "...", "values": ["Badan Air", ...], "truncated": false }
 * ```
 *
 * **Bounded, and the bound is what makes it usable.** There is no index inside
 * `properties` and there cannot be a useful one — `ix_layer_read` has no room
 * and a generated column per classifiable field is eight columns per layer
 * (specification section 6). So this is a scan, and the only question is
 * whether it terminates.
 *
 * `LIMIT` past the cap answers it, and answers it best in the worst case.
 * Measured on the 2.2-million-row land-use layer: `gunatanah_kategori`, 14
 * values, 1.77 s — the whole scan, because fourteen values means no early exit.
 * `upi` on the cadastre, 672,132 values, **0.00 s** — the 257th distinct value
 * arrives within the first few hundred rows and the scan stops there. The
 * pathological field is the cheap one; a field worth classifying by is the
 * expensive one, and a second and a half once per configuration is fine.
 *
 * **No counts.** A `COUNT(*)` needs `GROUP BY`, `GROUP BY` cannot stop early,
 * and grouping the cadastre by `upi` exhausted a 256 MB memory limit while this
 * was being measured. The legend counts loaded features on the client instead,
 * which is the honest number anyway: section 10 requires classification over a
 * viewport to be labelled as the subset it is.
 */
class LayerValuesController extends Controller
{
    public function __invoke(Request $request, Layer $layer): JsonResponse
    {
        try {
            $field = Classification::assertField($layer, $request->query('field'));
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $limit = Classification::MAX_CLASSES + 1;

        // The subquery is what bounds it. `DISTINCT ... LIMIT n` stops the scan
        // at the nth distinct value; a `LIMIT` applied after an ordering would
        // have to find them all first.
        $rows = DB::connection(config('gis.connection'))->select(
            'SELECT v FROM (SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(properties, ?)) AS v '
            .'FROM `gis_features` FORCE INDEX (ix_layer) WHERE layer_id = ? LIMIT '.$limit.') d',
            [Classification::path($field), $layer->id],
        );

        $truncated = count($rows) > Classification::MAX_CLASSES;

        $values = [];

        foreach ($rows as $row) {
            if ($row->v !== null && $row->v !== '') {
                $values[] = (string) $row->v;
            }
        }

        // Sorted here rather than in SQL: an ORDER BY would defeat the LIMIT's
        // early exit, and a list of at most 256 strings is nothing to sort.
        // Natural order so "Zon 2" precedes "Zon 10".
        natcasesort($values);

        return new JsonResponse([
            'field' => $field,
            'values' => $truncated ? [] : array_values($values),
            'truncated' => $truncated,
            'max' => Classification::MAX_CLASSES,
        ]);
    }
}
