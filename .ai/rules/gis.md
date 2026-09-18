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
