<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\StaticMapService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the cached static map PNG behind the latlng_map column, with browser
 * cache headers so each map is fetched once per client for the cache lifetime.
 */
class StaticMapController extends Controller
{
    public function show(Request $request, StaticMapService $maps): Response
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'w' => 'nullable|integer|between:50,1280',
            'h' => 'nullable|integer|between:50,1280',
            'z' => 'nullable|integer|between:1,20',
        ]);

        $map = $maps->get(
            (float) $data['lat'],
            (float) $data['lng'],
            (int) ($data['w'] ?? 200),
            (int) ($data['h'] ?? 120),
            (int) ($data['z'] ?? 15),
        );

        $ttlDays = $map['fallback'] ? StaticMapService::FALLBACK_TTL_DAYS : StaticMapService::CACHE_TTL_DAYS;

        $response = response($map['bytes'], 200, ['Content-Type' => 'image/png'])
            ->setEtag(sha1($map['bytes']))
            ->setLastModified(\Carbon\Carbon::createFromTimestamp($map['cached_at']))
            ->setPrivate()
            ->setMaxAge($ttlDays * 86400);

        if (! $map['fallback']) {
            $response->setImmutable();
        }

        $response->isNotModified($request);

        return $response;
    }
}
