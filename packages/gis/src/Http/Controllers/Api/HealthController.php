<?php

namespace Gis\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class HealthController extends Controller
{
    /**
     * Confirm the API is registered and reachable behind the admin guard.
     *
     * `capabilities.geos` is reported false until S7 binds GeometryService;
     * the field exists from the first release so the client never has to
     * branch on its absence.
     */
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'capabilities' => [
                'geos' => false,
                'maxBatch' => (int) config('gis.write.max_batch'),
            ],
        ]);
    }
}
