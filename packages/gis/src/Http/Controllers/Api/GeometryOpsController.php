<?php

namespace Gis\Http\Controllers\Api;

use Brick\Geo\Geometry;
use Gis\Geometry\GeometryService;
use Gis\Support\GeometryInput;
use Gis\Validation\GeometryValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * `POST /api/geo/geometry/ops` — constructive geometry above the client's
 * vertex limit.
 *
 * **It does not create anything.** The result comes back as geometry and the
 * client decides what to do with it: a buffer becomes a new feature through
 * `feature.create`, a difference replaces one through `feature.update`. Keeping
 * the two apart is what lets the command endpoint stay the only write path —
 * atomic, idempotent and replayable — while this one stays a pure function.
 *
 * **The routing threshold is advisory.** `capabilities.inlineOpVertexLimit`
 * tells the client when to stop doing this in Turf, but the endpoint does not
 * check how the caller decided: a small geometry sent here is computed here,
 * and that is a correct answer rather than a policy violation.
 */
class GeometryOpsController extends Controller
{
    /** Operations taking one geometry, and the arguments each needs. */
    private const UNARY = ['buffer', 'convexHull', 'centroid', 'pointOnSurface', 'simplify', 'makeValid'];

    /** Operations taking two. */
    private const BINARY = ['difference', 'intersection'];

    public function __invoke(Request $request, GeometryService $geometry): JsonResponse
    {
        $validated = $request->validate([
            'op' => ['required', 'string', 'max:32'],
            'geom' => ['required'],
            'geomEncoding' => ['sometimes', 'string', 'in:wkb,geojson'],
            'other' => ['sometimes'],
            'metres' => ['sometimes', 'numeric', 'between:-100000,100000'],
            'toleranceMetres' => ['sometimes', 'numeric', 'between:0,100000'],
        ]);

        $op = $validated['op'];

        if (! in_array($op, [...self::UNARY, ...self::BINARY, 'union'], true)) {
            return $this->problem('unknown_op', "There is no {$op} operation.", 422);
        }

        if (! $geometry->available()) {
            // 501 rather than 500: the request is fine and the server simply
            // cannot do it here. A deployment without the binary should read
            // as a missing capability, not as a crash.
            return $this->problem('geos_unavailable', 'Constructive geometry is not available on this server.', 501);
        }

        try {
            $subject = GeometryInput::parse($request->all());
            $result = $this->apply($geometry, $op, $subject, $request);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->problem('operation_failed', $e->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            return $this->problem('operation_failed', 'The operation could not be completed.', 422);
        }

        if ($result->isEmpty()) {
            // A real answer to "shrink this by more than it is wide", and the
            // client needs to be able to tell it apart from a failure.
            return new JsonResponse(['op' => $op, 'empty' => true, 'geom' => null]);
        }

        return new JsonResponse([
            'op' => $op,
            'empty' => false,
            // Base64 WKB, the same encoding a command carries geometry in, so
            // the result can be handed straight to `feature.create`.
            'geom' => \Gis\Casts\GeometryCast::toWkbBase64($result),
            'geomEncoding' => 'wkb',
        ]);
    }

    protected function apply(GeometryService $service, string $op, Geometry $subject, Request $request): Geometry
    {
        if (in_array($op, self::BINARY, true)) {
            $other = GeometryInput::parse([
                'geom' => $request->input('other'),
                'geomEncoding' => $request->input('geomEncoding', 'wkb'),
            ]);

            return $op === 'difference'
                ? $service->difference($subject, $other)
                : $service->intersection($subject, $other);
        }

        return match ($op) {
            'buffer' => $service->buffer($subject, (float) $request->input('metres', 0)),
            'union' => $service->union([$subject, GeometryInput::parse([
                'geom' => $request->input('other'),
                'geomEncoding' => $request->input('geomEncoding', 'wkb'),
            ])]),
            'convexHull' => $service->convexHull($subject),
            'centroid' => $service->centroid($subject),
            'pointOnSurface' => $service->pointOnSurface($subject),
            'simplify' => $service->simplify($subject, (float) $request->input('toleranceMetres', 1)),
            'makeValid' => $service->makeValid($subject),
        };
    }

    protected function problem(string $code, string $detail, int $status): JsonResponse
    {
        return new JsonResponse(['code' => $code, 'detail' => $detail], $status);
    }
}
