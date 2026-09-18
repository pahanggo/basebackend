---
paths:
  - 'packages/gis/**'
---

# Gis

## GIS package: settled architecture decisions
These were decided when the spec was adapted to this codebase. Do not re-litigate; see `packages/gis/specs/` — the specification plus one execution plan per session.

- **Do NOT run `php artisan backpack:crud` for this package.** The root CLAUDE.md instruction does not apply here: the generator cannot read GEOMETRY columns, generated columns or the fractional-index sort key. Migrations, models, controllers and requests are hand-written.
- **Assets build through the existing Vite pipeline** (`packages/gis/resources/js/**` as a root `vite.config.js` input). No import maps, no `public/vendor/`, no bespoke manifest command.
- **Leaflet is the already-vendored `public/packages/leaflet` UMD build, used as the global `L`** — never imported, never a second copy. Other JS deps come from npm; shpjs/supercluster/georaster stay behind `await import()`.
- **UI chrome is jQuery plus a hand-written `virtual-list.js` row recycler.** No lit-html, no Alpine, no third templating idiom.
- **API lives at `/api/geo`** under the admin guard with session cookie + CSRF — not Sanctum bearer, no `/v1` path segment.
- **Every table carries the `gis_` prefix**: `gis_maps`, `gis_layers`, `gis_features`, `gis_measurements`, `gis_map_user`.
- **Authorization is a spatie permission for module access plus the `gis_map_user.role` pivot for per-map access.** Never a second role system.
- **Constructive geometry goes through `GeometryService`** (`brick/geo` + GEOS, approved). Bind it with a resolver closure — Octane is enabled, so no container/request/config injection and no static accumulation.
- **Tiles come from `config('services.map_tiles')`**; where a same-origin tile path is needed, extend the existing `App\Services\StaticMapService`, do not build a second proxy.
- **Pest is the only test runner.** No Vitest, no standalone Playwright. Pure JS units are covered by a Pest browser test over a testing-only harness page.
- User-facing strings are translated into `lang/ms_MY.json` as elsewhere in the app.

## GIS package: settled architecture decisions
These were decided when the spec was adapted to this codebase. Do not re-litigate; see `packages/gis/specs/` — the specification plus one execution plan per session.

- **Do NOT run `php artisan backpack:crud` for this package.** The root CLAUDE.md instruction does not apply here: the generator cannot read GEOMETRY columns, generated columns or the fractional-index sort key. Migrations, models, controllers and requests are hand-written.
- **Assets build through the existing Vite pipeline** (`packages/gis/resources/js/**` as a root `vite.config.js` input). No import maps, no `public/vendor/`, no bespoke manifest command.
- **Leaflet is the already-vendored `public/packages/leaflet` UMD build, used as the global `L`** — never imported, never a second copy. Other JS deps come from npm; shpjs/supercluster/georaster stay behind `await import()`.
- **UI chrome is jQuery plus a hand-written `virtual-list.js` row recycler.** No lit-html, no Alpine, no third templating idiom.
- **API lives at `/api/geo`** under the admin guard with session cookie + CSRF — not Sanctum bearer, no `/v1` path segment.
- **Every table carries the `gis_` prefix**: `gis_maps`, `gis_layers`, `gis_features`, `gis_measurements`, `gis_map_user`.
- **Authorization is a spatie permission for module access plus the `gis_map_user.role` pivot for per-map access.** Never a second role system.
- **Constructive geometry goes through `GeometryService`** (`brick/geo` + GEOS, approved). Bind it with a resolver closure — Octane is enabled, so no container/request/config injection and no static accumulation.
- **Tiles come from `config('services.map_tiles')`**; where a same-origin tile path is needed, extend the existing `App\Services\StaticMapService`, do not build a second proxy. The service sends `access-control-allow-origin: *` (verified), so client-side export is viable.
- **Pest is the only test runner.** No Vitest, no standalone Playwright. Pure JS units are covered by a Pest browser test over a testing-only harness page.
- User-facing strings are translated into `lang/ms_MY.json` as elsewhere in the app.

**Data and rendering — measured from `bencana`, not assumed:**
- v1 data is imported from `bencana.lots` (672k) and `bencana.usages` (746k) by a one-off `gis:import-bencana` command. Not a sync. File import stays v2.
- `bencana` geometry is SRID 4326 stored in MySQL's native lat-lng axis order; read it with `axis-order=long-lat`. No reprojection needed.
- **Area culling, not simplification, is the LOD mechanism.** Parcels average 6.7 vertices — a quadrilateral cannot be simplified. 41% are under 500 m², so dropping below 4 px² holds the drawn set near 13,000 at every zoom (159,654 candidates at z12 → 12,929 drawn).
- The cull runs **server-side** in the feature read; never ship 160k features for the client to discard. `gis_features.area_m2` with `(layer_id, area_m2)` index exists for it.
- One map holds the whole state. Editing is disabled below zoom 16.
- Real-time co-editing is expected within a year: v1 ships optimistic locking but builds per-field merge, a replay-shaped command log, and Reverb.

## GIS package: settled architecture decisions
Decided while adapting the spec to this codebase. Do not re-litigate; see `packages/gis/specs/` — the specification plus one execution plan per session.

**Build and platform**
- **Do NOT run `php artisan backpack:crud` for this package.** The root CLAUDE.md instruction does not apply: the generator cannot read GEOMETRY columns, generated columns or the fractional-index sort key. Everything is hand-written.
- Assets build through the existing Vite pipeline (`packages/gis/resources/js/**` as a root `vite.config.js` input). No import maps, no `public/vendor/`, no bespoke manifest command.
- **Leaflet is the already-vendored `public/packages/leaflet` UMD build, used as the global `L`** — never imported, never a second copy.
- UI chrome is jQuery plus a hand-written `virtual-list.js` row recycler. No lit-html, no Alpine.
- API at `/api/geo` under the admin guard, session cookie + CSRF. No Sanctum bearer, no `/v1` segment.
- `GeometryService` = `brick/geo` + GEOS. Bind with a resolver closure — Octane is on, so no container/request/config injection, no static accumulation.
- Tiles from `config('services.map_tiles')`; extend `App\Services\StaticMapService` rather than building a second proxy. It sends `access-control-allow-origin: *` (verified), so client-side export is viable.
- Pest only. Pure JS units run via a Pest browser test over a testing-only harness page.
- **The app has no scheduler** (`Kernel::schedule()` is empty, no cron in repo). S0 registers `gis:sweep` and documents the cron entry; without it, soft deletes are permanent and orphaned overlay images are never reclaimed.
- Strings translated into `lang/ms_MY.json`.

**Data model**
- All tables carry the `gis_` prefix.
- **`gis_layers` (identity) and `gis_map_layer` (per-map placement) are separate tables.** A layer is placed in many maps; identity = name/kind/style/attr_schema/extent/feature_count/owner_map_id, placement = map_id/parent_id/sort_key/visible/opacity/zoom range/access. Separate `version` on each. `owner_map_id` NULL = global layer. Must be done in S1 — retrofitting means migrating live rows.
- Sharing copies nothing. Map copy shares layers; it never duplicates features (the old 20,000-row cap is void).
- Access: `owner` / `edit` / `read` on the placement. `edit` reaches the **layer** (rename, style, schema) so a restyle is global. Effective permission = narrowest of (user's role on map, placement access, layer locked). `layer.setAccess` refused unless the acting map owns the layer.
- **Layer library modal is "Add from library", not "Import"** — import means file import (v2). Lists global layers + layers of maps the user may open, nothing else. `edit` offered only where the user already holds edit rights; the server re-checks on `layer.share` because a modal is not an authorization boundary.
- Authorization = spatie permission for module access + `gis_map_user` pivot per map. Never a second role system.

**Data and rendering — measured from `bencana`, not assumed**
- v1 data imported from `bencana.lots` (672k) and `bencana.usages` (746k) by one-off `gis:import-bencana`. Creates the two layers as **global** layers, once per deployment. Not a sync. File import stays v2.
- `bencana` geometry is SRID 4326 in MySQL's native lat-lng axis order; read with `axis-order=long-lat`. No reprojection.
- **Area culling, not simplification, is the LOD mechanism.** Parcels average 6.7 vertices — a quadrilateral cannot be simplified. 41% are under 500 m², so dropping below 4 px² holds the drawn set near 13,000 at every zoom (159,654 candidates at z12 → 12,929 drawn).
- The cull runs **server-side** in the feature read; never ship 160k features for the client to discard. `gis_features.area_m2` + `(layer_id, area_m2)` index exists for it.
- One map holds the whole state. Editing disabled below zoom 16.
- Co-editing expected within a year: v1 ships optimistic locking but builds per-field merge, a replay-shaped command log, and Reverb.
- Magnetic bearing dropped — true azimuth only.

## GIS geometry engine: GEOS is planar, transform either side
`brick/geo` has **no pure-PHP engine**. It drives `geosop` (GEOS 3.15.0, `/opt/homebrew/bin/geosop`) via `GeosOpEngine`.

**GEOS treats coordinates as unitless Cartesian numbers.** `geosop buffer 250` on `POINT(103.326 3.8077)` returns a polygon spanning longitude 353 / latitude -241 — it read 250 as degrees. `area` returns square degrees. Never hand it lon/lat with a distance in metres.

Every constructive operation goes through:
```
ST_Transform(geom, <metric SRID>)   -- MySQL, 4326 -> metres
  -> geosop bufferQuadSegs <d> 32   -- GEOS, metres in and out
  -> ST_Transform(result, 4326)     -- MySQL, back to storage
```

- **32 quadrant segments, never the default 8.** Measured on a 250 m buffer at Kuantan: 8 → 248.79 m, -0.647% area error (breaches the 0.1% client/server budget in spec §11); 32 → 249.91 m, -0.046%.
- **Metric SRID per geometry** from centroid longitude: UTM 47N (32647) west of 102°E, 48N (32648) east. The data extent (101.33–104.21°E) straddles it, so it cannot be hardcoded.
- **No `proj4php`.** MySQL's `ST_Transform` does both legs; all ten registry SRIDs (3168, 3375, 3376, 3857, 4245, 4742, 24547, 24548, 32647, 32648) exist in `INFORMATION_SCHEMA.ST_SPATIAL_REFERENCE_SYSTEMS` and the 4326 round trip is exact.
- `geosop` is a subprocess: timeout it, bound the input, pass WKT on **stdin** — never interpolate geometry into a shell argument.

Also note: `composer require --dry-run` still writes to composer.json. Revert it afterwards.

## GIS package: how it is wired in, and where its data lives
Settled in S0/S1 and verified by the test suite. These override the earlier "add it to the root" wording above.

- **The package is a Composer path repository, not a root psr-4 entry.** `packages/gis/composer.json` carries its own autoload, its own `extra.laravel.providers` and its own `brick/geo` requirement; the root only lists the repository and requires `pahanggo/gis`. After editing the package's `composer.json`, run `composer update pahanggo/gis` — a `dump-autoload` alone will not pick up a new psr-4 prefix.
- **A root `extra.laravel.providers` entry does nothing.** `package:discover` builds only from `vendor/composer/installed.json`, so the root package's own block is never read. Providers for root-autoloaded packages go in `config/app.php`; discovered packages need neither.
- **The schema lives in its own database** (`webgis`, `gis` connection), defined in `packages/gis/config/database.php` and registered by the provider — `config/database.php` has no GIS entry. Consequence: **no foreign keys to `users`**; `gis_maps.owner_id` and `gis_map_user.user_id` are plain integers the application layer validates.
- Migrations set `$this->connection = config('gis.connection')` in their constructor. The migrator swaps the default connection for the duration of `up()`, so plain `Schema::create` lands in the right database while the `migrations` bookkeeping table stays in the application database.
- **Tests need MySQL 8**, not SQLite: SRID-aware geometry and spatial indexes do not exist there. `phpunit.xml` points at `basebackend_testing` and `webgis_test`. Use `Gis\Testing\RefreshesGisDatabase`, never `RefreshDatabase` directly — the latter leaves the GIS tables standing and the second run fails on "table already exists".
- The provider registers `gis:sweep` on the schedule itself; `app/Console/Kernel.php` is untouched.

## GIS geometry: what MySQL hands back, and how the cast reads it
- A plain column read returns MySQL's **internal format**: 4-byte little-endian SRID, then standard WKB. Those WKB bytes are byte-identical to `ST_AsBinary(geom, 'axis-order=long-lat')` — storage is longitude-latitude, even though `ST_AsText` without the option reports latitude first.
- **Detect bare WKB before assuming a SRID prefix.** A bare WKB point starts `01 01 00 00 00`, whose first four bytes read as a plausible SRID. Read the byte-order flag and the geometry type it implies first; only then strip.
- **Geometry is interpolated, not bound.** MySQL takes no placeholder inside `ST_GeomFromText`'s options argument, so `GeometryCast::literal()` builds the call as a string. Safe only because the WKT comes from `WktWriter` over parsed coordinates and is checked against a character class with no quote in it. Never interpolate a geometry string from anywhere else.
- `HasFactory::newFactory()` has no return type. A typed `newFactory(): ?Factory` on a base model is a fatal signature conflict, and PHP reports it as a silent exit with no test output. Use `Gis\Models\HasGisFactory`.

## GIS cadastral import: what S1b measured
- **The source is not clean.** `ST_IsValid` rejects 11,638 of 1,418,216 `bencana` rows (3 lots, 11,635 usages), almost all self-intersections. `gis:import-bencana` skips them and lists their ids in `storage/app/gis-import-rejects-*.txt`. Bring them in once `GeometryService::makeValid` exists (S7).
- **Every imported feature carries its source row id under the `_src` property.** That is what `--resume` reads, and how the rejected rows are found again. An import that is interrupted and re-run without `--fresh` or `--resume` is refused, because appending instead of resuming corrupts the layer silently — it happened once.
- **The viewport query should not use `ST_Intersects`.** The planner picks `sx_geom` correctly, but filtering `minx/maxx/miny/maxy` plus `area_m2` returns the identical rows 3.5x faster (0.48 s against 1.69 s for 13,974 of 164,109 candidates), because it never evaluates a geodesic predicate. Leave the exact test to the client, which re-culls anyway. The two differ by a ~2 m sliver at the viewport edge, since `ST_Intersects` on 4326 treats envelope edges as geodesics.
- **The 4 px² cull constant was calibrated to a smaller viewport.** At 1456x840 it yields 13,974 drawn for `lots` and 17,539 for `usages` at zoom 12 — 31,513 together, against a 10,000 design target. 8 px² brings `lots` to 11,699. S2 decides whether the threshold becomes a target count the server solves for.
- **Three MySQL limits the import works around:** `ST_Envelope` is not implemented for geographic SRSs; there is no `ST_NumPoints` for a polygon (and one row has 2,164 interior rings, so no expression can loop); `ST_Simplify` is Cartesian only and may emit invalid output. The first two go through `ST_SRID(g, 0)` and the WKB layout respectively — both safe because MySQL stores 4326 internally as longitude-latitude.
- `ST_Area` on SRID 4326 **is** geodesic and returns square metres: it agrees with the source's surveyed `keluasan` to 0.058% on average. That agreement also validates the axis order.

## GIS renderer: what S2 measured, and the traps it hit
- **Never call `Path2D.closePath()` in the feature paint loop.** 16,180 calls on one accumulating path measured 2,151 ms of a 2,177 ms repaint; Chrome charges each call in proportion to the whole path so far. It is not needed: every polygon ring arrives with its first vertex repeated. Fill and stroke were never the cost — they measured 1.3 ms and 0.0 ms for the same path.
- **Client coordinates are stored projected** (normalised Web Mercator, `[0, 1]`), not as longitude and latitude. Projecting per frame costs a `sin` and a `log` per vertex — 21 ms of a 34 ms repaint. The index, the hit test and the cull all work in projected units; `unproject` recovers lng/lat where editing and export need it. Verified against Leaflet to 0.5 px over 500 features.
- **Paths are built once per zoom and translated per frame** with `ctx.setTransform`. Pan frames went from 44 ms to 0.00 ms. Build in coordinates shifted by a build-time origin, never absolute world pixels — canvas paths hold points as 32-bit floats and lose sub-pixel precision above zoom 17.
- **Repositioning the canvas container is not optional, and it belongs in `draw()`.** The canvases sit inside Leaflet's map pane, which Leaflet moves to pan, so they already travel with the tiles; translating the cached path as well makes features pan at **twice** the speed of the basemap. Call `setPosition(container, containerPointToLayerPoint([0, 0]))` every draw, not just on `moveend`. The arithmetic looks right either way — the paint matched `latLngToContainerPoint` to half a pixel while the bug was live — so only the eye or a browser test catches it.
- **Do not use rbush's `search`**: it allocates an item object per hit, and a zoom-12 query matches 36,000. Walk the tree and collect indices into a reused `Uint32Array` (17 ms → 2.9 ms). The cull compacts in place into that same buffer.
- **Never refetch on every `moveend`.** Read with a quarter-viewport of padding and refetch only when the view leaves it. Sessions are file-based, so requests serialise: forty small pans became forty queued multi-megabyte requests and a frozen tab. Padding is not free — half a viewport each way quadruples the response.
- Budgets on the dev machine, both layers, 40,000 features: pan repaint 0.00 ms, cold rebuild 75.7 ms, index+cull 2.9 ms, hit test p95 0.2 ms, heap 30 MB. **The reference-device (4x throttle) gate has never been measured**; take it before S8 and S9 add work to the paint path.

## GIS feature read: the GIS1 format and where the time actually goes
- **Encoding is negotiated by `Accept`** (`application/vnd.gis.features+gis1` or `application/geo+json`), never a `?format=` parameter. Binary is 2.4x smaller and up to 6x faster to turn into typed arrays.
- **The encoder copies WKB coordinate runs as bytes** and converts no numbers: MySQL's WKB ordinates are already little-endian doubles, which is what `Float64Array` wants. Encoding 20,000 features costs under a millisecond.
- **The client views the buffer; the only pass over it is the projection, in place.** Never copy or re-pack — that is the whole reason the format exists.
- **The read is bounded by a cap, not by the area threshold.** Order by `area_m2 DESC` and take N: `ix_layer_area` serves that with a backward index scan and no filesort, and what falls off the end is the least visible thing on screen. A square-pixel threshold bounds nothing, because a bigger window just gets more features.
- **The read costs ~30 µs per feature and it is MySQL, not PHP.** Fetching only the id column costs the same as fetching geometry and encoding it; raw PDO instead of Eloquent saves 14%. So the 150 ms budget is about 5,000 features. Forcing `ix_layer_bbox` is 1.5x faster at 4 px².
- **The feature read picks its own index; do not leave it to the planner.** `ORDER BY area_m2 DESC LIMIT n` tempts MySQL into reading `ix_layer_area` backwards, which is right when the area threshold is selective and ruinous when it is not — at zoom 16 it walked most of 672,000 rows and took 1.8 s a layer. Forcing `ix_layer_bbox` there is 0.16 s; at zoom 10 the same hint is 25x *slower*. MySQL cannot choose, estimating 770,775 rows either way for want of statistics on correlated columns, so `gis.read.bbox_index_max_share` decides from the viewport's share of the layer extent. **It is tuned against `min_area_px`** — a bigger cull makes the threshold more selective and moves the crossover down. The principled replacement is a stored area distribution per layer.
- **Do not add a `COUNT(*)` for response metadata.** Two counts over 174,000 candidates measured at two thirds of the request. Counts go after the features (GeoJSON) or in a header (binary, which is not streamed).
- Attributes are **off by default** (`fields=1` to include them); for the imported cadastre they are most of the payload.
- A streamed response must actually flush, or PHP buffers the whole body and nothing streams.
