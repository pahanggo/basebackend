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

        // Restoring a soft-deleted map or layer is an administrator action,
        // not an owner one. An owner may delete their own map and may not undo
        // it themselves — the usual shape for a destructive action with a
        // recovery path, and it keeps the recovery auditable to a small group
        // (specification section 8).
        'admin_permission' => 'Administer GIS',
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
        'zoom' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Basemaps
    |--------------------------------------------------------------------------
    |
    | The provider list comes from the tile service rather than from here, so a
    | provider added to the service appears without a deployment and one removed
    | stops being offered instead of rendering broken tiles. It is fetched
    | server-side, cached, and delivered in the bootstrap: fetching it from the
    | browser would put a second origin on the critical path for a list that
    | changes a few times a year (specification section 13).
    |
    | The tile URL template itself is NOT here. It is `config('services.map_tiles')`,
    | which the latlng_picker CRUD field already uses, so a deployment that
    | repoints its tiles repoints every map in the application at once.
    |
    */

    'basemaps' => [
        'providers_url' => env('GIS_TILE_PROVIDERS_URL', 'https://tiles.pahanggo.com/providers'),
        'cache_key' => 'gis.basemap.providers',
        'cache_hours' => 24,
        'timeout_seconds' => 5,

        // The last resort: one basemap the user cannot change beats a map that
        // will not load.
        'default' => env('GIS_DEFAULT_BASEMAP', 'google-roadmap'),

        // Overlays are classified by prefix, so a new one needs no code change.
        'overlay_prefix' => 'owm-',
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
        'min_area_px' => 1,
        'max_features_per_response' => 1000000,
        'edit_min_zoom' => 16,

        // Features per streamed chunk. The binary encoding sends one complete
        // `GIS1` document per chunk and the readable one flushes at the same
        // interval, so the client paints a chunk at a time instead of waiting
        // for the whole read. Rows arrive biggest-first, so the first chunk is
        // the most visible thing on the screen.
        //
        // Smaller is more responsive and costs more frames: each chunk is a
        // worker round trip, an index insert and a path append.
        'stream_chunk' => 100,

        // Decimal places kept for a coordinate in the binary encoding, below
        // the editing zoom. Each ordinate becomes a biased uint32 rather than
        // a float64, halving the coordinate section — which is two thirds of
        // the payload. 7 is a resolution of 1e-7 degrees, about 1.1 cm, and a
        // worst-case error of half that; 0 disables it and sends float64.
        //
        // NOT applied at or above `edit_min_zoom`: there a coordinate can be
        // dragged and sent back, and rounding it on the way out would write the
        // rounding into storage.
        'coord_exponent' => 5,
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

        // The command log is two things with two lifetimes. Idempotency needs
        // 24 hours; replay-catch-up wants longer, so a client that was closed
        // over a weekend can still be brought forward rather than re-reading a
        // map whose layers hold 1.4 million features. The longer figure is what
        // the sweep enforces.
        'command_log_days' => 7,
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
