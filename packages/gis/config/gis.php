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
    | Bulk import
    |--------------------------------------------------------------------------
    |
    | Where v1's data comes from. PLANMalaysia publishes the cadastre, the land
    | use and the administrative boundaries as public, read-only ArcGIS feature
    | services, and `Gis\Database\Seeders\GunatanahSeeder` pulls them into global
    | layers once per deployment. It is not a sync: once imported, the features
    | belong to this application and are edited here. File import — a user
    | uploading a shapefile — is a separate, later thing (v2).
    |
    | The land-use pair are not the same thing: `semasa` is land use as surveyed
    | on the ground, `zoning` is land use as a local plan allocates it. The
    | mapping below was read off each service's own layer name rather than
    | inferred from the URL, because the two are easy to transpose and nothing
    | downstream would notice — a zoning allocation stored as a survey is a
    | plausible-looking map that is simply wrong.
    |
    | The boundary sources are administrative outlines, national rather than
    | Pahang-only and three orders of magnitude smaller: they exist to be drawn
    | over the land use, not culled against it. Narrow one with its `where`, as
    | the commented filters show, if a deployment wants only its own state.
    |
    */

    'import' => [

        'arcgis' => [
            // The services root. Each source names its folder and service below,
            // because they are not all in one folder: land use is under `iPLAN`,
            // the boundaries under `SCHARMS`.
            'base_url' => env(
                'GIS_ARCGIS_BASE_URL',
                'https://scharms.planmalaysia.gov.my/arcgis/rest/services',
            ),

            // Features per request, as a span of OBJECTID. Measured against
            // GTsemasa_06: a thousand rows is ~330 KB and 3.4 s wherever in the
            // table it falls, because the window is an indexed range rather than
            // an offset the server has to count past.
            //
            // A source may override it, and the boundary layers all do: their
            // features are whole administrative outlines, so the window is a
            // memory budget rather than a page size.
            'window' => 1000,

            // Windows in flight at once. The fetch is latency-bound, and the
            // service is someone else's: four is polite and roughly halves a
            // three-hour job.
            'concurrency' => 4,

            'timeout_seconds' => 180,
            'retries' => 3,
            'retry_sleep_ms' => 2000,

            // The ledger of committed windows, which is what makes an interrupted
            // import resume instead of doubling. See `Gis\Support\ImportLedger`.
            'progress_disk' => env('GIS_ARCGIS_PROGRESS_DISK', 'local'),
            'progress_folder' => 'gis/arcgis',

            /*
            | Each source names its service, its layer, the global layer it fills,
            | and how its fields are stored.
            |
            | `attributes` maps a SERVICE field to `[property name, type]`. The
            | rename is the point: the services carry names truncated to fit a
            | shapefile's ten-character column limit — `nama_neger`, `seksyen_na`,
            | `luas_hekta`, `mukim_name` — and those would otherwise be what every
            | popup, label, filter and export in this application says forever. One
            | vocabulary across every source (`negeri`, `daerah`, `mukim`,
            | `seksyen`, `upi`), so a feature from a boundary layer and a feature
            | from the cadastre answer the same question with the same key.
            |
            | `type` is what `attr_schema` reports to the client: `string` or
            | `number`. `where` is optional and defaults to `1=1`.
            */

            'sources' => [

                'lot' => [
                    'service' => 'iPLAN/LOT_06',
                    'layer' => 0,
                    'name' => 'Lot',

                    'attributes' => [
                        'UPI' => ['upi', 'string'],
                        'NEGERI' => ['negeri', 'string'],
                        'DAERAH' => ['daerah', 'string'],
                        'MUKIM' => ['mukim', 'string'],
                        'SEKSYEN' => ['seksyen', 'string'],
                        'LOT' => ['no_lot', 'string'],
                        'KELUASAN' => ['keluasan', 'number'],
                    ],

                    'style' => ['stroke' => '#8a6d3b', 'weight' => 1, 'fill' => '#f0ad4e', 'fillOpacity' => 0.15],
                ],

                'gunatanah_semasa' => [
                    'service' => 'iPLAN/GTsemasa_06',
                    'layer' => 0,
                    'name' => 'Gunatanah Semasa',

                    // Land use as surveyed on the ground, classified three levels
                    // deep: Komersial > Perkhidmatan > Agensi Perkhidmatan.
                    'attributes' => [
                        'lot_upi' => ['lot_upi', 'string'],
                        'kod_gtn' => ['kod_gunatanah', 'string'],
                        'gunatanah1' => ['gunatanah_kategori', 'string'],
                        'gunatanah2' => ['gunatanah_subkategori', 'string'],
                        'gunatanah3' => ['gunatanah_terperinci', 'string'],
                        'nama' => ['nama', 'string'],
                        'tahun_data' => ['tahun_data', 'number'],
                        'luas_hekta' => ['luas_hektar', 'number'],
                        'negeri_nam' => ['negeri', 'string'],
                        'daerah_nam' => ['daerah', 'string'],
                        'mukim_name' => ['mukim', 'string'],
                        'seksyen_na' => ['seksyen', 'string'],
                        'pbt_name' => ['pbt', 'string'],
                    ],

                    'style' => [
                        'stroke' => '#2f6f3e',
                        'weight' => 1,
                        'fill' => '#7bc47f',
                        'fillOpacity' => 0.2,
                    ],
                ],

                'gunatanah_zoning' => [
                    'service' => 'iPLAN/GTzoning_06',
                    'layer' => 0,
                    'name' => 'Gunatanah Zoning',

                    // Land use as a local plan allocates it: one level, and the
                    // plan that allocated it.
                    'attributes' => [
                        'lot_upi' => ['lot_upi', 'string'],
                        'kod_gtn' => ['kod_gunatanah', 'string'],
                        'gunatanah1' => ['gunatanah', 'string'],
                        'nama_ranca' => ['nama_rancangan', 'string'],
                        'tahun_data' => ['tahun_data', 'number'],
                        'luas_hekta' => ['luas_hektar', 'number'],
                        'negeri_nam' => ['negeri', 'string'],
                        'daerah_nam' => ['daerah', 'string'],
                        'mukim_name' => ['mukim', 'string'],
                        'seksyen_na' => ['seksyen', 'string'],
                        'pbt_name' => ['pbt', 'string'],
                    ],

                    'style' => [
                        'stroke' => '#5b3f8c',
                        'weight' => 1,
                        'fill' => '#b39ddb',
                        'fillOpacity' => 0.2,
                    ],
                ],

                // Persempadanan: the electoral and local-authority boundaries.
                'parlimen' => [
                    'service' => 'SCHARMS/Persempadanan',
                    'layer' => 0,
                    'name' => 'Sempadan Parlimen',
                    'window' => 10,
                    'attributes' => [
                        'UPI' => ['upi', 'string'],
                        'Nama' => ['nama', 'string'],
                        'Negeri' => ['negeri', 'string'],
                    ],
                    'style' => ['stroke' => '#b71c1c', 'weight' => 2, 'fill' => '#ef5350', 'fillOpacity' => 0.05],
                ],

                'dun' => [
                    'service' => 'SCHARMS/Persempadanan',
                    'layer' => 1,
                    'name' => 'Sempadan DUN',
                    'window' => 10,
                    'attributes' => [
                        'UPI' => ['upi', 'string'],
                        'Nama' => ['nama', 'string'],
                        'Parlimen' => ['parlimen', 'string'],
                        'Negeri' => ['negeri', 'string'],
                    ],
                    'style' => ['stroke' => '#e65100', 'weight' => 2, 'fill' => '#ffb74d', 'fillOpacity' => 0.05],
                ],

                'pbt' => [
                    'service' => 'SCHARMS/Persempadanan',
                    'layer' => 2,
                    'name' => 'Sempadan PBT',
                    'window' => 10,
                    'attributes' => [
                        'UPI' => ['upi', 'string'],
                        'NamaPBT' => ['nama', 'string'],
                        'Singkatan' => ['singkatan', 'string'],
                        'KodNegeri' => ['kod_negeri', 'string'],
                        'NamaNegeri' => ['negeri', 'string'],
                    ],
                    'style' => ['stroke' => '#00695c', 'weight' => 2, 'fill' => '#4db6ac', 'fillOpacity' => 0.05],
                ],

                // Demarcation: the administrative hierarchy the cadastre's `negeri`,
                // `daerah` and `mukim` codes name.
                'negeri' => [
                    'service' => 'SCHARMS/Demarcation',
                    'layer' => 0,
                    'name' => 'Sempadan Negeri',

                    // Sarawak alone is a nineteen-megabyte GeoJSON document.
                    'window' => 2,
                    // 'where' => "kod_negeri = '06'",
                    'attributes' => [
                        'kod_negeri' => ['kod_negeri', 'string'],
                        'nama_neger' => ['nama', 'string'],
                    ],
                    'style' => ['stroke' => '#263238', 'weight' => 3, 'fill' => '#90a4ae', 'fillOpacity' => 0.04],
                ],

                'daerah' => [
                    'service' => 'SCHARMS/Demarcation',
                    'layer' => 1,
                    'name' => 'Sempadan Daerah',
                    'window' => 10,
                    // 'where' => "kod_negeri = '06'",
                    'attributes' => [
                        'kod_negeri' => ['kod_negeri', 'string'],
                        'kod_daerah' => ['kod_daerah', 'string'],
                        'nama_daera' => ['nama', 'string'],
                    ],
                    'style' => ['stroke' => '#37474f', 'weight' => 2, 'fill' => '#b0bec5', 'fillOpacity' => 0.04],
                ],

                'mukim' => [
                    'service' => 'SCHARMS/Demarcation',
                    'layer' => 2,
                    'name' => 'Sempadan Mukim',
                    'window' => 10,
                    // 'where' => "kod_negeri = '06'",
                    'attributes' => [
                        'kod_negeri' => ['kod_negeri', 'string'],
                        'kod_daerah' => ['kod_daerah', 'string'],
                        'kod_mukim' => ['kod_mukim', 'string'],
                        'nama_mukim' => ['nama', 'string'],
                    ],
                    'style' => ['stroke' => '#455a64', 'weight' => 1, 'fill' => '#cfd8dc', 'fillOpacity' => 0.04],
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
