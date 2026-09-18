# S0 — Package skeleton

**Depends on:** nothing
**Specification:** §3 (module loading, file layout), §5, §17 (page shell), §22
**Gate:** the editor page loads behind the admin guard, the provider boots, `npm run build` is clean.

## Goal

Stand up `packages/gis/` as a wired-in package with one blank page, so every later session has somewhere to put things. No GIS behaviour ships here.

## In scope

- Directory skeleton and PSR-4 autoloading
- Service provider: config, routes, views, migrations
- Vite input registered, Leaflet loaded as a global
- `brick/geo` added to `composer.json`
- One admin route rendering an empty full-viewport map page
- The bootstrap blob the client reads its configuration from
- `gis:sweep` registered on the scheduler, plus the cron entry documented

## Out of scope

Tables (S1), the renderer (S2), any endpoint under `/api/geo` beyond a health check (S3+).

## Deliverables

```
packages/gis/
  composer.json               (metadata only; autoloading is wired in the root)
  src/
    GisServiceProvider.php
    Http/Controllers/EditorController.php
  config/gis.php
  routes/web.php              admin-guarded page route
  routes/api.php              /api/geo, empty but registered
  src/Console/SweepCommand.php
  database/migrations/        empty, S1 fills it
  resources/
    js/main.js                boots, logs, nothing else
    scss/gis.scss
    views/editor.blade.php
  specs/                      these files
```

Root-level changes:

- `composer.json` — add `"Gis\\": "packages/gis/src"` to `autoload.psr-4`, add `Gis\GisServiceProvider` to `extra.laravel.providers`, add `brick/geo`. This follows the existing `packages/backpack/*` precedent: the package is autoloaded from the root, not installed through a path repository.
- `vite.config.js` — add `packages/gis/resources/js/main.js` and the package stylesheet as inputs.

## Constraints that apply here

- **The editor view extends `base/layouts/plain.blade.php`**, not `top_left`. Add menu to access the editor view on the `menu.blade.php` similar to `kitchensink`. Add a back button on top left of the editor view to reaccess the dashboard. Override the layout's `container` wrapper and centred body class — the map needs full bleed (§17).
- **Leaflet is a `<script>` tag pointing at `public/packages/leaflet/dist/leaflet.js`**, placed before the bundle. Never an import, never a second copy (§3).
- The client reads configuration from one JSON blob rendered into the page, not from scattered `data-` attributes: tile URL and attribution from `config('services.map_tiles')`, CSRF token, current map id, capabilities.
- `php artisan backpack:crud` is not used in this package, now or later.
- **The application has no scheduler.** `Kernel::schedule()` is empty and there is no cron, Procfile or supervisor config in the repository. Three things in the specification expire — soft-deleted rows at 30 days, exports at 24 hours, orphaned overlay images — and none of them would ever run. Register `gis:sweep` here and document the `schedule:run` cron entry, even though it has nothing to sweep yet. If the deployment declines the cron entry, say so in the README: soft deletes become permanent retention and orphaned images are never reclaimed.
- Every user-facing string goes through translation helpers with Bahasa Melayu in `lang/ms_MY.json`.

## Gate

- Visiting the admin route renders a full-viewport page with a working Leaflet basemap and no console errors.
- An unauthenticated request redirects rather than rendering.
- `npm run build` succeeds and the built asset is loaded through `@vite`.
- `composer dump-autoload` resolves `Gis\` classes; the provider appears in `php artisan about`.

## Tests

- Feature: the route is behind the admin guard; a guest is redirected.
- Feature: the page renders and the bootstrap blob contains the configured tile URL.
- Architecture (Pest arch): nothing under `packages/gis/src` imports from `App\Http\Controllers\Admin`.
- Feature: `gis:sweep` runs clean against an empty database and is idempotent.

## Notes

`config/gis.php` should carry the overlay storage disk and folder, the upload limits from §20 and the feature-read caps, so S5b and S3 have somewhere to read them from rather than hardcoding.

## Results

_Fill in when complete._
