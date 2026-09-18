<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | The package keeps its own schema in its own database, so the 1.4 million
    | cadastral rows never sit beside the application's tables. Every migration,
    | model and query in this package resolves its connection from here; nothing
    | uses the default connection. There are no foreign keys to `users` — they
    | would cross databases — so `gis_maps.owner_id` and `gis_map_user.user_id`
    | are plain integers validated in the application layer.
    |
    */

    'connection' => env('GIS_DB_CONNECTION', 'gis'),

    /*
    |--------------------------------------------------------------------------
    | One-off cadastral import
    |--------------------------------------------------------------------------
    |
    | `gis:import-bencana` copies the state cadastre in once per deployment. It
    | is not a sync: once imported, the features belong to this application and
    | are edited here. File import is a separate, later thing (v2).
    |
    */

    'import' => [
        'bencana' => [
            'connection' => env('GIS_BENCANA_CONNECTION', 'bencana'),

            // Rows per INSERT ... SELECT. Both databases sit on the same MySQL
            // server, so rows never travel through PHP.
            'chunk' => 2000,

            // Degrees. Roughly three pixels at zoom 12 (38.1 m/px at this
            // latitude), which is the band `geom_simple` exists to serve.
            'simplify_tolerance' => 0.001,

            'layers' => [
                'lots' => [
                    'table' => 'lots',
                    'name' => 'Lot',
                    'attributes' => ['upi', 'negeri', 'daerah', 'mukim', 'seksyen', 'no_lot', 'keluasan'],
                ],
                'usages' => [
                    'table' => 'usages',
                    'name' => 'Gunatanah',
                    'attributes' => ['lot_upi', 'kod_gtn', 'gunatanah1'],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Route registration
    |--------------------------------------------------------------------------
    |
    | The editor page sits under the Backpack admin prefix behind the admin
    | guard; the API sits at /api/geo behind the same guard with session cookie
    | and CSRF, not a bearer token (specification section 7).
    |
    */

    'route' => [
        'web_prefix' => 'gis',
        'api_prefix' => 'api/geo',
        'permission' => 'Access GIS',
    ],

    /*
    |--------------------------------------------------------------------------
    | Map defaults
    |--------------------------------------------------------------------------
    |
    | Where a new map opens when it carries no view state of its own. Kuantan,
    | at a zoom where editing is still disabled.
    |
    */

    'default_view' => [
        'center' => [103.3260, 3.8077],
        'zoom' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rendering and read caps
    |--------------------------------------------------------------------------
    |
    | `min_area_px` is the area cull that holds the drawn set near 13,000
    | features at every zoom (specification section 4). It is applied
    | server-side in the feature read; the client re-culls for exactness.
    |
    */

    'read' => [
        'min_area_px' => 4,
        'max_features_per_response' => 30000,
        'edit_min_zoom' => 16,

        // The viewport's share of a layer's extent below which the feature
        // read forces `ix_layer_bbox` instead of letting MySQL choose. Tuned
        // against `min_area_px`: raising that constant makes the area
        // threshold more selective and moves the crossover down. See
        // FeatureReadController::shouldForceBoundingBoxIndex().
        'bbox_index_max_share' => 0.01,
    ],

    'write' => [
        'max_batch' => 500,
        'max_vertices_per_feature' => 50000,
        'max_wkb_bytes' => 4 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Overlay images
    |--------------------------------------------------------------------------
    |
    | Uploaded plans and scans, re-encoded on arrival so nothing but pixels
    | survives (specification section 20).
    |
    */

    'overlays' => [
        'disk' => env('GIS_OVERLAY_DISK', 'public'),
        'folder' => 'gis/overlays',
        'max_bytes' => 20 * 1024 * 1024,
        'max_edge_px' => 10000,
        'mime_types' => ['image/png', 'image/jpeg'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'reads_per_minute' => 300,
        'writes_per_minute' => 60,
        'image_uploads_per_hour' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | What `gis:sweep` purges, in days. The sweep only runs if the deployment
    | has a `schedule:run` cron entry; see the package README.
    |
    */

    'retention' => [
        'soft_deleted_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Geometry engine
    |--------------------------------------------------------------------------
    |
    | GEOS is planar and unit-agnostic, so every constructive operation is
    | projected to metres, computed, and projected back (specification section
    | 5). 32 quadrant segments, never the geosop default of 8.
    |
    */

    'geometry' => [
        'geosop' => env('GIS_GEOSOP_PATH', '/opt/homebrew/bin/geosop'),
        'timeout_seconds' => 15,
        'buffer_quad_segs' => 32,
        'metric_srid' => [
            'west' => 32647,        // UTM 47N, west of 102 deg E
            'east' => 32648,        // UTM 48N, east of it
            'split_longitude' => 102.0,
        ],
    ],

];
