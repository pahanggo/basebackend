<?php

namespace Gis\Http\Controllers\Api;

use Brick\Geo\Geometry;
use Gis\Casts\GeometryCast;
use Gis\Geometry\GeometryService;
use Gis\Models\Layer;
use Gis\Support\Classification;
use Gis\Support\GeometryInput;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * `POST /api/geo/layers/{layer}/query` — which features stand in a given
 * relation to a given shape, optionally narrowed by their attributes.
 *
 * **Two indexes, and they cannot be used together.** MySQL will not combine
 * its R-tree with a B-tree in one plan, which is the whole reason the
 * redundant `minx/miny/maxx/maxy` columns exist (specification §6). So every
 * query here runs in two stages: a **candidate** pass over the bounding-box
 * columns, which `ix_layer_read` serves, and then the exact predicate over
 * what survives. The exact test is the expensive one and it runs on the small
 * set.
 *
 * **The candidate count is checked before the exact pass, not after.** A query
 * whose bounding box covers the state has a candidate set of two million, and
 * running the predicate over it would take minutes and then be refused anyway.
 * Counting first refuses in milliseconds, which is the difference between an
 * error and a hang.
 */
class QueryController extends Controller
{
    /**
     * The relations offered, mapped to the MySQL predicate for each.
     *
     * `disjoint` is the odd one and is handled separately: everything is
     * disjoint from a small shape, so the bounding box prefilter that bounds
     * every other relation selects exactly the wrong rows for this one.
     */
    private const RELATIONS = [
        'intersects' => 'ST_Intersects',
        'within' => 'ST_Within',
        'contains' => 'ST_Contains',
        'crosses' => 'ST_Crosses',
        'touches' => 'ST_Touches',
    ];

    /** Candidate ceiling. Above this the query is refused rather than run. */
    private const MAX_CANDIDATES = 200_000;

    public function __invoke(Request $request, Layer $layer, GeometryService $geometry): JsonResponse
    {
        $validated = $request->validate([
            'relation' => ['sometimes', 'string', 'in:intersects,within,contains,crosses,touches,disjoint'],
            'geom' => ['sometimes'],
            'geomEncoding' => ['sometimes', 'string', 'in:wkb,geojson'],
            'bufferMetres' => ['sometimes', 'numeric', 'between:-100000,100000'],
            'where' => ['sometimes', 'array'],
            'where.*.field' => ['required_with:where', 'string', 'max:64'],
            'where.*.op' => ['required_with:where', 'string', 'in:=,!=,<,<=,>,>=,contains,starts,in'],
            'where.*.value' => ['sometimes'],
            'return' => ['sometimes', 'string', 'in:ids,count,features'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);

        $relation = $validated['relation'] ?? null;
        $shape = null;

        if ($request->has('geom')) {
            try {
                $shape = GeometryInput::parse($request->all());

                if (($validated['bufferMetres'] ?? 0) != 0) {
                    // Computed here, before the predicate. MySQL cannot buffer
                    // geographic geometry at all — its buffer is Cartesian and
                    // on a geographic system returns degrees, so there is no
                    // SQL-only path (specification §5).
                    $shape = $geometry->buffer($shape, (float) $validated['bufferMetres']);
                }
            } catch (InvalidArgumentException|RuntimeException $e) {
                return $this->problem('query_invalid', $e->getMessage(), 422);
            }
        }

        if ($relation !== null && $shape === null) {
            return $this->problem('query_invalid', 'A spatial relation needs a geometry to relate to.', 422);
        }

        try {
            $conditions = $this->conditions($layer, $validated['where'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->problem('query_invalid', $e->getMessage(), 422);
        }

        $candidates = $this->candidates($layer, $shape, $relation, $conditions);
        $count = (clone $candidates)->count();

        if ($count > self::MAX_CANDIDATES) {
            // Refused rather than run. The count is named so the user can see
            // how much narrowing it needs rather than guessing.
            return $this->problem('query_too_broad', sprintf(
                'That query would examine %s features; the limit is %s. Narrow the area or the filter.',
                number_format($count),
                number_format(self::MAX_CANDIDATES),
            ), 422);
        }

        $matched = $this->exact($candidates, $shape, $relation);
        $return = $validated['return'] ?? 'ids';
        $limit = (int) ($validated['limit'] ?? 10_000);

        if ($return === 'count') {
            return new JsonResponse(['count' => $matched->count(), 'candidates' => $count]);
        }

        $rows = (clone $matched)->select('id')->limit($limit + 1)->pluck('id');
        $capped = $rows->count() > $limit;
        $ids = $rows->take($limit)->map(fn ($id) => (int) $id)->all();

        if ($return === 'ids') {
            return new JsonResponse([
                'ids' => $ids,
                'count' => count($ids),
                'candidates' => $count,
                'capped' => $capped,
            ]);
        }

        return new JsonResponse([
            'type' => 'FeatureCollection',
            'candidates' => $count,
            'capped' => $capped,
            'features' => $this->features($layer, $ids),
        ]);
    }

    /**
     * The candidate query: cheap filters only, on indexed columns.
     *
     * The bounding box is the whole point. `ix_layer_read` is
     * `(layer_id, area_m2, minx, maxx, miny, maxy)`, so these four comparisons
     * are answered by index condition pushdown without reading a row — which
     * is what turns two million features into a few thousand before anything
     * expensive happens.
     *
     * @param  array<int, array{sql: string, bindings: array<int, mixed>}>  $conditions
     */
    protected function candidates(Layer $layer, ?Geometry $shape, ?string $relation, array $conditions): Builder
    {
        $query = DB::connection(config('gis.connection'))
            ->table('gis_features')
            ->where('layer_id', $layer->id);

        // `disjoint` is everything NOT touching the shape, so a bounding-box
        // prefilter would keep precisely the rows that cannot match. Its
        // candidate set is the whole layer, which usually means it is refused
        // — and being refused is the honest answer to "find me everything in
        // two million rows that is not near this point".
        if ($shape !== null && $relation !== null && $relation !== 'disjoint') {
            $box = $shape->getBoundingBox();

            $query->where('minx', '<=', $box->getNorthEast()->x())
                ->where('maxx', '>=', $box->getSouthWest()->x())
                ->where('miny', '<=', $box->getNorthEast()->y())
                ->where('maxy', '>=', $box->getSouthWest()->y());
        }

        foreach ($conditions as $condition) {
            $query->whereRaw($condition['sql'], $condition['bindings']);
        }

        return $query;
    }

    /** The exact spatial test, over whatever survived the candidate pass. */
    protected function exact(Builder $candidates, ?Geometry $shape, ?string $relation): Builder
    {
        if ($shape === null || $relation === null) {
            return $candidates;
        }

        $literal = GeometryCast::literal($shape);

        if ($relation === 'disjoint') {
            return $candidates->whereRaw("NOT ST_Intersects(`geom`, {$literal})");
        }

        return $candidates->whereRaw(self::RELATIONS[$relation]."(`geom`, {$literal})");
    }

    /**
     * Attribute predicates, as bound SQL fragments.
     *
     * **The field is checked against `attr_schema` and the JSON path is
     * bound**, never interpolated — the same rule the classification follows.
     * The value is bound too, so a `where` clause cannot become part of the
     * query however it is spelled.
     *
     * These ride no index. `attr_schema` marks which fields are indexed and §6
     * describes promoting those to generated columns; that promotion is S9's
     * schema work and is not built, so an attribute-only query is a scan
     * bounded by the candidate ceiling rather than by an index.
     *
     * @param  array<int, array<string, mixed>>  $where
     * @return array<int, array{sql: string, bindings: array<int, mixed>}>
     */
    protected function conditions(Layer $layer, array $where): array
    {
        $out = [];

        foreach ($where as $clause) {
            $field = Classification::assertField($layer, $clause['field'] ?? null);
            $path = Classification::path($field);
            $value = $clause['value'] ?? null;
            $extract = 'JSON_UNQUOTE(JSON_EXTRACT(`properties`, ?))';

            $out[] = match ($clause['op']) {
                'contains' => ['sql' => "{$extract} LIKE ?", 'bindings' => [$path, '%'.self::escapeLike((string) $value).'%']],
                'starts' => ['sql' => "{$extract} LIKE ?", 'bindings' => [$path, self::escapeLike((string) $value).'%']],
                'in' => $this->inCondition($extract, $path, $value),
                // Numeric comparisons cast, because a JSON extract is text and
                // '9' > '10' is true in text.
                '<', '<=', '>', '>=' => [
                    'sql' => "CAST({$extract} AS DECIMAL(20,6)) {$clause['op']} ?",
                    'bindings' => [$path, (float) $value],
                ],
                default => ['sql' => "{$extract} {$clause['op']} ?", 'bindings' => [$path, (string) $value]],
            };
        }

        return $out;
    }

    /**
     * @param  mixed  $value
     * @return array{sql: string, bindings: array<int, mixed>}
     */
    protected function inCondition(string $extract, string $path, mixed $value): array
    {
        $values = is_array($value) ? array_values($value) : [$value];
        $values = array_slice($values, 0, 500);

        if ($values === []) {
            // An empty list matches nothing, which is what it says. `IN ()` is
            // a syntax error in MySQL, so it is written out.
            return ['sql' => '1 = 0', 'bindings' => []];
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return [
            'sql' => "{$extract} IN ({$placeholders})",
            'bindings' => [$path, ...array_map('strval', $values)],
        ];
    }

    /** `%` and `_` are wildcards in LIKE; a user typing them means them literally. */
    protected static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * The matched features as GeoJSON, with their versions and attributes.
     *
     * The same shape the `ids` read returns, so a caller that already knows how
     * to read one knows how to read the other.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    protected function features(Layer $layer, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::connection(config('gis.connection'))
            ->table('gis_features')
            ->where('layer_id', $layer->id)
            ->whereIn('id', $ids)
            ->select(['id', 'version', 'area_m2', 'properties', DB::raw(GeometryCast::selectGeoJson('geom', 'geometry'))])
            ->get()
            ->map(fn ($row) => [
                'type' => 'Feature',
                'id' => (int) $row->id,
                'geometry' => json_decode($row->geometry, true, flags: JSON_THROW_ON_ERROR),
                'properties' => [
                    '_area' => (float) $row->area_m2,
                    '_version' => (int) $row->version,
                    'attributes' => json_decode($row->properties ?: '{}', true),
                ],
            ])
            ->all();
    }

    protected function problem(string $code, string $detail, int $status): JsonResponse
    {
        return new JsonResponse(['code' => $code, 'detail' => $detail], $status);
    }
}
