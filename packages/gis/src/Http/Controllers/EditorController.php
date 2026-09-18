<?php

namespace Gis\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class EditorController extends Controller
{
    /**
     * Render the full-viewport editor page.
     */
    public function index(Request $request): View
    {
        return view('gis::editor', [
            'bootstrap' => $this->bootstrap($request),
        ]);
    }

    /**
     * The single JSON blob the client reads its configuration from.
     *
     * Everything the editor needs before its first request lives here, so no
     * part of the client reads scattered `data-` attributes or holds English
     * literals of its own (specification sections 3 and 17).
     *
     * @return array<string, mixed>
     */
    protected function bootstrap(Request $request): array
    {
        return [
            'csrfToken' => csrf_token(),
            'apiBase' => url(config('gis.route.api_prefix')),
            'mapId' => null,
            'basemap' => [
                'url' => config('services.map_tiles.url'),
                'attribution' => config('services.map_tiles.attribution'),
                'maxZoom' => 20,
            ],
            'view' => config('gis.default_view'),
            'capabilities' => [
                'geos' => false,
                'maxBatch' => (int) config('gis.write.max_batch'),
                'maxFeaturesPerResponse' => (int) config('gis.read.max_features_per_response'),
                'editMinZoom' => (int) config('gis.read.edit_min_zoom'),
                'minAreaPx' => (float) config('gis.read.min_area_px'),
            ],
            'strings' => [
                'backToDashboard' => __('Back to dashboard'),
                'loading' => __('Loading'),
            ],
        ];
    }
}
