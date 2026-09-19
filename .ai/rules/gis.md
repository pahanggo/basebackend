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

**Data and rendering — measured from the real cadastre, not assumed:**
- v1 data is imported from PLANMalaysia's ArcGIS services by a one-off `GunatanahSeeder`. Not a sync. File import stays v2.
- Geometry is SRID 4326 stored in MySQL's native lat-lng axis order; read it with `axis-order=long-lat`. No reprojection needed.
- **Area culling is the only LOD mechanism, and simplification is GONE.** Parcels average 6.7 vertices — a quadrilateral cannot be simplified. 41% are under 500 m², so dropping below 4 px² holds the drawn set near 13,000 at every zoom (159,654 candidates at z12 → 12,929 drawn).
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

**Data and rendering — measured from the real cadastre, not assumed**
- v1 data imported from PLANMalaysia's ArcGIS services by the one-off `GunatanahSeeder`. Creates every layer as a **global** layer, once per deployment. Not a sync. File import stays v2.
- Geometry is SRID 4326 in MySQL's native lat-lng axis order; read with `axis-order=long-lat`. No reprojection.
- **Area culling is the only LOD mechanism, and simplification is GONE.** Parcels average 6.7 vertices — a quadrilateral cannot be simplified. 41% are under 500 m², so dropping below 4 px² holds the drawn set near 13,000 at every zoom (159,654 candidates at z12 → 12,929 drawn).
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

## GIS cadastral data: what S1b measured
These are properties of the cadastre and of MySQL, not of any one importer. `gis:import-bencana` and its `bencana` connection are GONE — `GunatanahSeeder` reads the ArcGIS services instead — but the measurements below still hold.

- **The source is not clean.** `ST_IsValid` rejects 11,638 of 1,418,216 cadastral rows, almost all self-intersections. Geometry that fails every predicate it is later used in is worse than a missing row, so a rejected feature is named rather than discovered later. Bring them in once `GeometryService::makeValid` exists (S7).
- **Every imported feature carries its source row id under the `_src` property.** It is reserved in `PropertySanitizer` so a command cannot forge it, and it is how a rejected row is found again.
- **The viewport query should not use `ST_Intersects`.** The planner picks `sx_geom` correctly, but filtering `minx/maxx/miny/maxy` plus `area_m2` returns the identical rows 3.5x faster (0.48 s against 1.69 s for 13,974 of 164,109 candidates), because it never evaluates a geodesic predicate. Leave the exact test to the client, which re-culls anyway. The two differ by a ~2 m sliver at the viewport edge, since `ST_Intersects` on 4326 treats envelope edges as geodesics.
- **The 4 px² cull constant was calibrated to a smaller viewport.** At 1456x840 it yields 13,974 drawn for lots and 17,539 for land use at zoom 12 — 31,513 together, against a 10,000 design target. 8 px² brings lots to 11,699. S2 decides whether the threshold becomes a target count the server solves for.
- **Three MySQL limits an import works around:** `ST_Envelope` is not implemented for geographic SRSs; there is no `ST_NumPoints` for a polygon (and one row has 2,164 interior rings, so no expression can loop); `ST_Simplify` is Cartesian only and may emit invalid output. The first two go through `ST_SRID(g, 0)` and the WKB layout respectively — both safe because MySQL stores 4326 internally as longitude-latitude. `FeatureIngest` sidesteps both by computing the bounding box and the vertex count in PHP from the GeoJSON it already has.
- `ST_Area` on SRID 4326 **is** geodesic and returns square metres: it agrees with the source's surveyed `keluasan`/`luas_hektar` to 0.058% on average. That agreement also validates the axis order.

## GIS renderer: what S2 measured, and the traps it hit
- **Never call `Path2D.closePath()` in the feature paint loop.** 16,180 calls on one accumulating path measured 2,151 ms of a 2,177 ms repaint; Chrome charges each call in proportion to the whole path so far. It is not needed: every polygon ring arrives with its first vertex repeated. Fill and stroke were never the cost — they measured 1.3 ms and 0.0 ms for the same path.
- **Client coordinates are stored projected** (normalised Web Mercator, `[0, 1]`), not as longitude and latitude. Projecting per frame costs a `sin` and a `log` per vertex — 21 ms of a 34 ms repaint. The index, the hit test and the cull all work in projected units; `unproject` recovers lng/lat where editing and export need it. Verified against Leaflet to 0.5 px over 500 features.
- **Paths are built once per zoom and translated per frame** with `ctx.setTransform`. Pan frames went from 44 ms to 0.00 ms. Build in coordinates shifted by a build-time origin, never absolute world pixels — canvas paths hold points as 32-bit floats and lose sub-pixel precision above zoom 17.
- **Repositioning the canvas container is not optional, and it belongs in `draw()`.** The canvases sit inside Leaflet's map pane, which Leaflet moves to pan, so they already travel with the tiles; translating the cached path as well makes features pan at **twice** the speed of the basemap. Call `setPosition(container, containerPointToLayerPoint([0, 0]))` every draw, not just on `moveend`. The arithmetic looks right either way — the paint matched `latLngToContainerPoint` to half a pixel while the bug was live — so only the eye or a browser test catches it.
- **There is no level-of-detail geometry anywhere in this package. Do not reintroduce it.** No client simplification, no `geom_simple`, no vertex mask. A feature is drawn with the vertices it was imported with, or the area cull drops it whole — dropping a feature is honest, reshaping one is not. Measured before removal: the mask saved 0.03–1.3% of vertices across zooms 11–15, and a full path rebuild was 24.6 ms with it against 24.8 ms without, i.e. nothing. It had also been silently destroying large features since S2, because its threshold was a degrees-era constant left behind when coordinates moved to normalised Web Mercator, and its vertex floor kept whichever vertices the loop had not reached — a 120-vertex estate boundary kept vertices 0, 117, 118 and 119, four points clustered at one spot, painting as a hairline.
- **The area cull is the ONLY thing that varies what is drawn by zoom**, and it varies which features, never their shape. Coordinate quantisation varies precision below the editing zoom and is not LOD in this sense: every vertex still arrives.
- **Do not use rbush's `search`**: it allocates an item object per hit, and a zoom-12 query matches 36,000. Walk the tree and collect indices into a reused `Uint32Array` (17 ms → 2.9 ms). The cull compacts in place into that same buffer.
- **Never refetch on every `moveend`.** Read with a quarter-viewport of padding and refetch only when the view leaves it. Sessions are file-based, so requests serialise: forty small pans became forty queued multi-megabyte requests and a frozen tab. Padding is not free — half a viewport each way quadruples the response.
- Budgets on the dev machine, both layers, 40,000 features: pan repaint 0.00 ms, cold rebuild 75.7 ms, index+cull 2.9 ms, hit test p95 0.2 ms, heap 30 MB. These are a regression baseline for later sessions, not a device-performance claim; the throttled mobile-class gate was dropped and replaced by real-device testing.

## GIS feature read: the GIS1 format and where the time actually goes
- **Encoding is negotiated by `Accept`** (`application/vnd.gis.features+gis1` or `application/geo+json`), never a `?format=` parameter. Binary is 2.4x smaller and up to 6x faster to turn into typed arrays.
- **The encoder copies WKB coordinate runs as bytes** and converts no numbers: MySQL's WKB ordinates are already little-endian doubles, which is what `Float64Array` wants. Encoding 20,000 features costs under a millisecond.
- **The client views the buffer; the only pass over it is the projection, in place.** Never copy or re-pack — that is the whole reason the format exists.
- **The read is bounded by a cap, not by the area threshold.** Order by `area_m2 DESC` and take N: `ix_layer_area` serves that with a backward index scan and no filesort, and what falls off the end is the least visible thing on screen. A square-pixel threshold bounds nothing, because a bigger window just gets more features.
- **The read costs ~30 µs per feature and it is MySQL, not PHP.** Fetching only the id column costs the same as fetching geometry and encoding it; raw PDO instead of Eloquent saves 14%. So the 150 ms budget is about 5,000 features. Forcing `ix_layer_bbox` is 1.5x faster at 4 px².
- **The feature read forces `ix_layer_read` and always has to.** The index is `(layer_id, area_m2, minx, maxx, miny, maxy)`: read backwards it returns rows already in area order, so there is NO filesort and the first row is immediate, and the four bbox columns let index condition pushdown reject ~97% of entries before any row lookup. **MySQL will never choose it** — the moment `geom` joins the select list the index stops covering, and the optimiser falls back to a table scan plus filesort. Measured on `Gunatanah`, 16,475 features at zoom 12: planner 395 ms / first row 368 ms, forced 96 ms / first row 0.4 ms.
- **Neither half of the read's WHERE is selective; only their intersection is.** At zoom 12 on a 615,373-feature layer the area threshold admits 37%, the bounding box 20%, the two together 2.7%. MySQL has no cross-column statistics, so it sees two weak filters and declines both indexes. That is the whole reason the hint is not optional.
- **`shouldForceBoundingBoxIndex()` and `gis.read.bbox_index_max_share` are GONE.** They existed because no single index served both filters and had to be re-tuned against `min_area_px`. `ix_layer_read` wins or ties at zooms 12/14/16/18 and is the only plan that never filesorts; `ix_layer_bbox` filesorts at every zoom, and at zoom 18 its 17 ms of total time costs 69 ms of first-row latency. Do not reintroduce the heuristic.
- **Do not add a `COUNT(*)` for response metadata.** Two counts over 174,000 candidates measured at two thirds of the request. Counts go last, after the features: the GeoJSON `cull` object follows the feature array and the binary one is a trailer frame. Neither can be a header — `returned`, `capped` and `smallestReturnedM2` are only known once the last row has been read, and headers are written before the first.
- **Both encodings stream, `gis.read.stream_chunk` (100) features at a time.** A `GIS1` document cannot stream, because its header carries section offsets known only at the end, so the binary response is a SEQUENCE of small complete documents — `uint8 kind`, `uint32 length`, payload — ending in a trailer. Its media type says so (`application/vnd.gis.features+gis1-stream`); do not send framed bytes under the old single-document type.
- **A streamed chunk is APPENDED to the client's arrays and to the built `Path2D`, never used to replace them.** Replacing per chunk rebuilds a growing path 233 times — about 1.9 s of main-thread work for one read, against 23 ms of appending. The accumulator mutates one geometry object in place because the renderer and the index hold a reference to it and re-read its fields; handing back a new object breaks both silently.
- **Streaming is only as progressive as the row source.** Before `ix_layer_read`, the filesort meant no first row for 368 ms and all 234 flushes fired in one burst at the end — streaming that streamed nothing, for the second time in this file. With the index forced, the endpoint went from 428–504 ms to first byte / 484–567 ms total, to **95–169 ms / 148–257 ms**. If you change the read's plan, re-check time to FIRST row, not just total.
- **`cursor()` is buffered, so PHP still waits for the whole result set before the first `echo`.** That is the last ~95 ms of time-to-first-byte. Unbuffered PDO would take it to ~1 ms, but it must be scoped to this one read and restored in a `finally` — no other query can use the connection while a cursor is open, which under Octane is a live hazard.
- **Coordinates are quantised below the editing zoom, and must not be at or above it.** GIS1 v2: flag bit 1, exponent in the old `reserved` word, each ordinate a `uint32` of `round((lng + 180) * 10^e)`. The bias is what keeps it unsigned and unsigned is what keeps it portable — PHP's `pack()` has no signed little-endian code, only machine order, and everything else in this format is explicitly LE. Above `edit_min_zoom` a vertex can be dragged and sent back, so a rounded read would write its own rounding into storage and move the vertex again on every edit.
- **Quantise once over the whole blob in `encode()`, never per ring.** `appendRing` stays a pure byte copy. 1.7M ordinates: unpack 26 ms, scale 34 ms, repack 12 ms. Per ring would pay PHP's call overhead 38,000 times for the same arithmetic.
- **The client expands quantised coordinates inside the projection pass it already ran.** That pass calls a `log` and a `tan` per vertex; a multiply and a subtract do not register beside them. It is the one place the format allocates — a `uint32` section cannot be widened in place — so the expanded coords travel as a second transferable buffer and `viewGis1` needs both.
- **`gzip_types` must list `application/vnd.gis.features+gis1-stream` and `application/geo+json`.** nginx's default list is HTML and JSON only, so the response shipped uncompressed for the whole of S3. It compresses to 0.45. This is deployment config, not repo config — a new environment loses it silently.
- **A pan sends `held`, the box it already has, and the read excludes anything whose bbox MEETS it.** Meets, not is contained by: a straddling feature was returned by the previous read, because that read asked for everything intersecting its box too. Intersection is what makes the two sets disjoint, and disjoint is what lets the client append without deduplicating. Measured: a quarter-viewport pan went from 17,316 rows / 6.32 MB to 841 rows / 0.36 MB, and the plan keeps no filesort because all four bbox columns are in `ix_layer_read`.
- **The client compacts to the padded area on EVERY additive read, unconditionally.** What is held must equal what `loaded` claims, or a pan back across older leftovers re-fetches features already present — and two identical rings in one `Path2D` filled `evenodd` cancel into a HOLE rather than showing as extra ink. Keeping the leftovers as a cache means deduplicating by id and an unbounded working set; the arrays are the largest thing the client owns.
- **Do not send `held` after a capped response.** A capped read dropped features inside the box it covers, and excluding that box next time would lose them until the zoom changes.
- Attributes are **off by default** (`fields=1` to include them); for the imported cadastre they are most of the payload.
- A streamed response must actually flush, or PHP buffers the whole body and nothing streams.

## GIS commands: the vocabulary, the log, and what the merge needs
Settled in S4. The client's command objects ARE the API payloads — one vocabulary, no adapter. If you find yourself writing a translation layer between store commands and API payloads, the shapes have drifted and one is the bug.

- **The command log is two tables, and the second one is load-bearing.** `gis_command_log` holds the batch and its response (it IS the idempotency store — written in the same transaction, so there is no window where work is committed and the key is not). `gis_command_effects` holds one row per row touched, with the version it produced and the FIELD NAMES it wrote (`geom`, `properties.status`). Without the field list, "did the other side touch what I am touching?" has no answer and every stale command becomes a blocking conflict. A handler that under-reports what it wrote silently loses someone else's edit.
- **The map version is the replay sequence.** It is already bumped once per batch and already tracked by the client; a second counter beside it could only disagree with it. `GET /api/geo/maps/{map}/commands?since=N` is an indexed range scan over it, and returns `canReplay: false` rather than replaying a history it no longer holds.
- **`feature.update`'s `properties` is a PATCH** (null removes a key). It has to be: a whole-object write clobbers a concurrent edit to a different key and leaves nothing to merge. `layer.setStyle` is the opposite — whole replacement — because a half-applied style renders nothing.
- **Geometry never enters the store.** The renderer owns coordinates. `featureUpdate` carries `geom` and `previousGeom` on the command for the wire and its inverse; it writes neither into state.
- **Undo cannot restore ids or versions, and must not.** Undoing a delete re-creates the row under a new server-assigned id, and the inverse goes to the server as an ordinary command that bumps the version again. Content round-trips; identity does not.
- `mapVersion` in the envelope is advisory. Gating on it would make any concurrent batch invalidate the next one whole and per-field merge would never be reachable — the per-row versions are the gate.
- Authorization composes three terms and takes the narrowest: the user's `gis_map_user.role`, the placement's `access`, the layer's `locked`. `MapAccess` is the only place that does it. A layer not placed in this map is unreachable through this map whatever the user's role elsewhere. `MapRole` (owner/editor/contributor/viewer) and `MapLayer::$access` (owner/edit/read) are different questions — never substitute one for the other.
- **Reverb is not installed.** The event, channel and its authorization ship and are tested; `BROADCAST_DRIVER=log`. Standing up the server is a deployment decision, not a code change.
- Only ten ops have handlers; the catalogue is spec §7 and `CommandRegistry` is extendable. An unhandled op fails with `unknown_op`, never silently.
- Property sanitising happens on INGEST (`PropertySanitizer`), not at display time — the value reaches exports and templates where only the ingest rule has held.
- `request->validate()` returns only keys it has rules for, so the commands are taken from `$request->input('commands')`. A `commands.*.op` rule would strip every command down to its op — and rules for the payloads would be a second copy of the catalogue.

## GIS client: one sequence counter, and what may be persisted
- **`(clientId, seq)` is ONE key space per tab, so there is one counter.** `clientId` is issued per page load and stable for its life. The map browser and the outbound queue used to count from one independently, so the first command after creating a map carried the pair the creation had already spent: the server read it as a replay and returned `{mapId}` instead of applying anything, the client reconciled an `applied` list that was not there, and the layer silently never appeared — until a reload, which issues a new `clientId`. `lib/seq.js` is the single source; anything that spends a seq takes it from there.
- **The server refuses a reused seq loudly** (`seq_reused`, 409) rather than replaying a row that is not a command batch. The silent version of this bug is the expensive one.
- **The map's `view_state` is written by an unversioned `PUT /maps/{map}/view`, never by `PATCH /maps/{map}`.** The map's `version` is the replay sequence for its command log, so bumping it for a pan would thread holes through that sequence and invalidate every other client's version many times a minute. A conflict dialog for a pan would be absurd: last move wins and nobody loses anything. Restricted to owner/editor so a viewer does not move everyone's starting view.
- **Only remember a view the USER caused.** `invalidateSize()` pans to keep the centre anchored and fires `moveend` for it, so a window resize or a dock opening would otherwise decide where the map opens tomorrow. The editor ignores moves for a moment around a resize, and treats the view a map arrived with as already stored so restoring never writes it back.

## GIS behind a TLS-terminating proxy
- **URLs that travel inside JSON or JavaScript must be root-relative.** The editor's `apiBase` is `/api/geo`, never `url(...)`. Behind a Cloudflare Tunnel the origin is reached over plain HTTP, so Laravel generates `http://` for an `https://` page — and Cloudflare's Automatic HTTPS Rewrites silently repairs links and assets in the HTML while leaving a URL inside a `<script type="application/json">` blob untouched. The symptom is a page that looks completely fine with one blocked mixed-content fetch. The API is same-origin by design (session cookie + CSRF), so an absolute URL buys nothing.
- **`App\Http\Middleware\TrustProxies` must name the proxy.** A null `$proxies` trusts nothing, so `X-Forwarded-Proto` is ignored. It now reads `config('app.trusted_proxies')`, defaulting to the loopback — which is where `cloudflared` connects from, since it runs on the host and talks to `http://basebackend.test`. Do NOT use `*`: this application reads the client IP (`SetTokenIp`), and trusting everyone lets anything that can reach the origin directly spoof `X-Forwarded-For`.

## GIS maps and sharing: where permission is actually enforced
Settled in S5a. Sharing copies nothing — a map copy placing a 734k-feature layer takes 3.6 ms because it writes one row per placement.

- **Effective permission is the narrowest of three terms**, resolved only in `Gis\Support\MapAccess`: the user's `gis_map_user.role`, the placement's `access`, the layer's `locked`. A map owner given a `read` placement cannot write through it; a layer not placed in this map is unreachable through this map whatever the user's role elsewhere. Visibility and opacity are the exception — they are this map's view of the layer, not a change to the layer, so a `read` placement may still set them.
- **`layer.setAccess` is refused unless the ACTING map owns the layer**, and the placement it changes usually lives in another map. That refusal is the whole reason there is no self-escalation path.
- **The library modal is not an authorization boundary.** It offers the levels the server will grant; `layer.share` re-checks them. There is a test that calls the command directly with `access: edit` — keep it.
- **Restore (`map.restore`, `layer.restore`) is administrators only**, including the owning map's owner, via the `Administer GIS` permission. Both are commands taking an `id`, sent through any open map — a soft-deleted map cannot receive a batch addressed to itself.
- **Fractional sort keys append by odometer, never by bisection.** Bisecting toward an open end converges on `z` and grows a character per insert: 500 appends produced an 84-character key against a `varchar(64)` column. `a0`→`a1`, `az`→`b0`. Client (`lib/sort-key.js`) and server (`Support/SortKey`) implement the same algorithm and must stay in step — a drop computes its key on the client, a copy computes one on the server.
- **The basemap list is fetched server-side and inlined in the bootstrap**, with three fallbacks (live → cache → configured default) and a `source` field saying which was used. It never throws and never returns empty: a basemap the user cannot change beats a map that will not load. `owm-` prefix classifies weather overlays, so a new one needs no code change. The tile URL template stays `config('services.map_tiles')` — its path is `{x}/{y}/{z}`, not Leaflet's default order, so the client must take the template rather than assemble one.
- The map listing's feature count sums **owned layers only**; summing shared ones prints the 1.4M cadastral base on every row.
- Delete refuses the currently-open map on the client's declared `?openMapId=` — only the client knows, and a policy cannot answer a question about a session.
- `Http::fake()` called twice MERGES stubs behind the first rather than replacing it, so a second fake never takes effect. Use one stub with a mutable holder object (an arrow function captures by value, so a captured bool will not flip).
- The `.html(`/`innerHTML` grep in PackageBoundaryTest is blunt enough that naming those accessors in a JS comment trips it. Reword the comment; do not weaken the pattern.

## ArcGIS import: what the PLANMalaysia services actually do
`GunatanahSeeder` reads nine layers from PLANMalaysia. Measured, not assumed:

- **Page by OBJECTID range, never `resultOffset`.** Deep offsets are catastrophic: three records at offset 2,000,000 on `GTsemasa_06` took 49 s; a 1,000-row OBJECTID window took 3.4 s anywhere in the table. Ranges are also what make the import resumable and concurrent — a window is defined by its bounds alone. Both iPLAN sources are dense (OBJECTID 1..N, no gaps).
- **Some records cannot be served.** `GTsemasa_06` OBJECTID 1460 answers `{"error":{"code":400,"message":"Failed to execute query."}}` on its own and poisons every window containing it, deterministically. A refused window is bisected to find it; without that, one bad record silently costs 1,000 features and the ledger records the window as done. Only an ArcGIS error document triggers bisection — a transport failure is the retry policy's job.
- **The service answers errors with HTTP 200.** A successful status proves nothing; check for the `error` key.
- **A window is a memory budget, not a page size.** One `Sempadan Negeri` feature (Sarawak) is ~19 MB of GeoJSON. 1,000 boundaries will not fit in any limit; the boundary sources override `window` down to 2–10. For the same reason `area_m2` is a second statement (`UPDATE ... SET area_m2 = ST_Area(geom)`) rather than inline in the INSERT — inline binds the same document twice and exhausted 256 MB.
- **Field names are renamed on ingest** (`nama_neger`→`nama`, `luas_hekta`→`luas_hektar`, `seksyen_na`→`seksyen`). The services carry shapefile ten-character truncations; stored names use one vocabulary across every source, so a boundary feature and a cadastral feature answer the same question with the same key. `attr_schema` describes the stored names.
- **`ST_GeomFromGeoJSON(CAST(? AS JSON), 2, 4326)` is the bulk-ingest path** and lives in `GeometryCast::geoJsonPlaceholder()` with the other raw spatial calls. No axis-order option and none is needed: GeoJSON is longitude-latitude by RFC 7946 and MySQL reads it that way whatever the SRS declares. Verified — Pahang's outline computes to 35,953 km² against an official 35,965, and `luas_hektar` matches `ST_Area` exactly.
- **A layer holding features with no ledger is refused, not appended to.** The ledger is the only record of which rows an interrupted import wrote; a layer filled some other way would be silently doubled.

## GIS: the imported data now, and what the boundary layers break
Nine global layers, 4,276,307 features, all from `GunatanahSeeder`. Measured, not assumed:

| layer | features | avg vertices | max vertices |
| --- | --- | --- | --- |
| Lot | 672,132 | 6.5 | — |
| Gunatanah Semasa | 2,863,522 | ~11 (sampled) | — |
| Gunatanah Zoning | 738,104 | ~17 (sampled) | — |
| Sempadan Negeri | 14 | 25,982 | 99,253 |
| Sempadan Daerah | 93 | 5,257 | 80,653 |
| Sempadan Mukim | 1,730 | 466 | 36,078 |
| Sempadan Parlimen / DUN / PBT | 166 / 445 / 101 | 2,750 / 1,538 / 2,181 | 13,974 |

- **The boundary layers invert §4's whole argument.** §4 says features are many, tiny and unsimplifiable quadrilaterals held near a constant drawn count by the area cull. Boundaries are few and enormous, and the cull NEVER drops one — a state is never smaller than a pixel — so a visible boundary layer contributes its whole vertex count to the built path at every zoom (364k vertices for the 14 states). That is one path build and then nothing, because paths are built per zoom and translated per frame. Do not put them in a budget viewport without saying so.
- **Three boundary features exceed `write.max_vertices_per_feature` (50,000).** They import fine — the cap is a write-path check on commands — and the layers are `locked`, so the lock refuses an edit before the cap is ever reached. Unlocking a boundary layer without raising the cap makes the refusal look like a bug.
- **The land-use taxonomy is shared**: `gunatanah_kategori` (Semasa) and `gunatanah` (Zoning) both carry the same 14 values. Semasa is three levels deep (`_kategori` / `_subkategori` / `_terperinci`), zoning is one.
- **The `SCHARMS` boundary services are national, the `iPLAN` ones are Pahang-only.** Narrow a boundary source with its config `where` on `kod_negeri`, never in code.

## GIS read bounds: min_area_px=1 and cap=1,000,000 are probably left over from S3
`gis.read.min_area_px` is **1** and `gis.read.max_features_per_response` is **1,000,000**. They were 4 and 30,000 until commit `cad8584` (the S3 commit that removed level of detail), which is where "every vertex loaded is painted, at every zoom" was verified — so they look like verification settings that were never restored.

Measured at those values, 1456x840 zoom-12 viewport over Kuantan, `Lot` alone: 25,573 features returned of 169,567 candidates, against §4's 10,000-feature design target. The cap never engages. 4 px² → 15,945; 8 px² → 9,145. Add the land-use layers and it is worse.

Do not silently retune: it changes the §19 budgets, which are a hard gate. Raise it as a decision. Recorded as the one open question in specification §23.

## GIS budgets: synthetic CPU throttling is not a gate — real devices replaced it
Supersedes the note recorded just before this one, which had the reason wrong.

§19 originally gated every session on a 4-core mobile-class device under a 4x DevTools CPU slowdown. That gate is **dropped** — not deferred, not an unpaid debt. The question it stood in for has been answered directly: the editor was tested on real devices over slow 3G and 4G, and the result was acceptable.

Per-session budgets are measured on the development machine, unthrottled, and compared against the previous session's numbers on the same machine. Treat them as a **regression check** — "did this change make something slower" — never as a claim about device performance. Device performance is established on devices.

Do not re-open this at the start of a session, do not add a throttle row back to a gate table, and do not describe the missing throttled measurement as outstanding. It was replaced, not skipped.

## GIS layer tree: index the tree, and what "absent" means on the view write
Settled in S5b.

- **Never traverse the tree by scanning `state.tree` per node.** `childrenOf` without an index is a full scan plus a sort; calling it once per node is quadratic. Measured at 2,000 nodes: flatten 98 ms, expand a group 67 ms against a 50 ms gate, visibility toggle 149 ms. With one `buildIndex(state)` per render pass: 6 ms, 1.6 ms, 3 ms. The same scan existed twice — `tree-model.flatten` and `main.js`'s `childrenInTreeOrder` — so fixing one is not fixing it. The index is a snapshot: build it per pass, never hold it across a mutation.
- **All tree arithmetic lives in `ui/tree-model.js`** — inheritance, tri-state, the cycle rule, where a drop lands. `layer-tree.js` is the view and holds none of it. Those are the parts that fail silently, and they have Node tests.
- **Rows are recycled**, so nothing may hold a row node across a render. A pooled node shows whichever row the list last gave it; the drop indicator is positioned in list coordinates for exactly this reason.
- **The view write merges, so an absent key means "unchanged".** That is deliberate — a client that knows only where it is looking must not erase the basemap. The consequence is that a client must send a key it wants *emptied*: omitting `overlays` when the list went empty meant unticking the last weather overlay never saved and it returned on reload. An empty list is a state the user chose, not an absence of information.
- **Opacity applies at the Leaflet pane**, one per top-level node. Groups, tile layers and image overlays fade; a single vector layer inside a group does not until per-layer styling lands (S8), because vector layers share one feature canvas.
- **`createStore` must build the `Store` before the `SyncQueue`.** The queue's `onApplied` closes over it and runs after the function returns; a `const` declared below the closure threw `ReferenceError` on every confirmed batch from S4 until S5b, and no test caught it because none reached the network.

## GIS renderer: stable slots, per-layer alpha, and the two opacities
Settled in S5b, after three performance bugs that all looked like UI bugs.

- **Never `clearGeometry()` for a visibility change.** It empties `_layers`, so every feed is recreated with `slot: null` and every *other* layer refetches and rebuilds its paths — megabytes for one checkbox. The drawn set is reconciled: keep the feed for a layer that is still wanted (slot, held area and all), `release()` one that is not, construct only what is new. `clearGeometry()` is for a map switch, where nothing carries over. Measured after: hiding one of three layers costs 0 feature reads.
- **A renderer slot is stable for the life of a layer.** Removal leaves a `null` hole that the next `addGeometry` fills; array position is NOT paint order. Paint order is the explicit `order` field, and every loop over `_layers` goes through `_ordered()` — which also skips the holes.
- **There is ONE opacity, and it is `gis_map_layer.opacity`.** `style.fillOpacity` is gone — from the seeded styles, from the renderer defaults and from the paint loop. It used to multiply the layer's opacity, which meant two controls for one visible property and a slider that could not reach opaque: the imported layers carried 0.15, so 100% painted at 15% and looked broken. Layers now import solid and are dialled back per map. Do not reintroduce a second alpha.
- **Layer opacity is `globalAlpha` in the paint loop, not a pane opacity.** A Leaflet pane can only fade a TOP-LEVEL tree node, because every vector layer shares one feature canvas — so a slider on a nested layer did nothing whatsoever. Groups, tiles and image overlays still fade through their pane.
- **A continuous control repaints on `input` and commits on `change`.** A range input fires `input` per pixel; committing there was a command, a round trip and a full reconcile per pixel. Wind the local value back to where the drag started before committing, or the command's inverse undoes to the preview rather than to where the layer was.
- **A paused sync queue must be surfaced.** A 409 pauses the queue and it then sends nothing at all — while the optimistic UI keeps showing every later change as applied, until a reload loses them. The only sign used to be a `console.warn`.

## GIS sublayers: classification is placement state, and why
Settled in S5d. A layer splits into sublayers by one feature property; the document lives on `gis_map_layer.classification`, NOT in `gis_layers.style`.

- **The specification said style until S5d and was wrong.** `layer.setStyle` goes through `MapAccess::mayEditLayer`, which refuses a locked layer — and all nine imported layers are locked. The only layers worth classifying were the only ones that design could not reach. It is also a *reading* of a layer, not a property of one: two maps may split the same shared cadastre by category and by district at once.
- Two ops, both PLACEMENT-scoped and authorised like `setVisible`/`setOpacity`: `layer.setClassification` (whole replacement, or null to clear) and `layer.setClassState` (patch one class, addressed by VALUE not index, `other` names the fallback bucket).
- **A class's opacity is not a second alpha.** Opacity already multiplies down the tree; a class is one more level of that chain. `style.fillOpacity` stays forbidden — it was a second control over ONE object. Do not remove one for resembling the other.
- **Filter on the client, never in the read.** No index exists inside `properties` and `ix_layer_read` has no room; a per-class server filter would scan once per visible class and break the `held`/append pan optimisation. Measured: toggling a class costs 0 feature reads and paints in 15–22 ms.
- Ship one narrow value per feature, never `properties`. Zoom 12 over Kuantan on the land-use layer: 15.33 MB plain, 15.49 MB with `classify=`, 41.39 MB with `fields=1`.

## GIS traps found in S5d: five failures that raise no error
All four cost real debugging time and none threw.

- **Anything `replaceGeometry` does not carry over is reset on every pan that reads.** It rebuilds the slot entry from a literal and is called by `evict()`, which runs after every read that dropped a feature. It already had to be taught `order` and `opacity`; S5d added `slotOf` and `paints`, after a classified layer reverted to its base colour the moment the map moved. `tests/js/renderer-slots.test.mjs` guards it — add a case there for any new per-slot field.
- **`FeatureAccumulator.compact()` rebuilds every typed array and silently drops what it does not know about.** Per-feature data belongs INSIDE `geometry`, beside `types`, never as a sibling array like `properties` — which is still not compacted and would desynchronise on the first eviction.
- **PHP encodes an empty map as `[]`, and `[].fill` is `Array.prototype.fill`.** So `entry.style?.fill ?? fallback` yields a FUNCTION, the fallback never runs, and assigning it to a CSS property gives an empty string. Read class styles through `classStyle()` only. Any `?.` into a PHP-sourced "object" has this hazard.
- **A JSON path binds as an ordinary placeholder**: `JSON_EXTRACT(properties, ?)` takes one perfectly well, unlike the geometry constructor's options argument. Never interpolate a property name into SQL. Naming `ST_GeomFromText` etc. in a *comment* trips PackageBoundaryTest's grep — reword the comment, never weaken the pattern.
- Bounded distinct discovery: `SELECT DISTINCT ... LIMIT n+1` makes the pathological field the FAST one (a unique key stops at the 257th value, 0.00 s) while `GROUP BY` for counts cannot stop early and exhausts 256 MB on the same field.
