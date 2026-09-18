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
  composer.json               (its own autoload, provider and dependencies)
  src/
    GisServiceProvider.php
    Http/Controllers/EditorController.php
  config/gis.php
  routes/web.php              admin-guarded page route
  routes/api.php              /api/geo, empty but registered
  src/Console/SweepCommand.php
  config/database.php         the `gis` connection, registered by the provider
  database/migrations/        empty, S1 fills it
  resources/
    js/main.js                boots, logs, nothing else
    scss/gis.scss
    views/editor.blade.php
  specs/                      these files
```

Root-level changes:

- `composer.json` — register `packages/gis` as a path repository and require `pahanggo/gis`. The package carries its own `autoload.psr-4`, its own `extra.laravel.providers` and its own `brick/geo` requirement, so nothing about it leaks into the application. This departs from the `packages/backpack/*` precedent, which is autoloaded from the root; see Results.
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

Done. The editor renders at `/app/gis` behind the admin guard and the `Access GIS`
permission, with a full-viewport Leaflet basemap and no console errors.

**The package is self-contained.** Rather than the root psr-4 entry the plan
assumed, it is a Composer **path repository**: `packages/gis` carries its own
`composer.json`, and the root requires `pahanggo/gis`. Laravel's package
discovery then reads the package's `extra.laravel.providers`, so there is no
entry in `config/app.php` — and `brick/geo` is the package's dependency rather
than the application's. The package also registers its own database connection
and its own `gis:sweep` schedule from the provider, so neither
`config/database.php` nor `app/Console/Kernel.php` is touched.

The plan's instruction to add the provider to the root `composer.json`'s
`extra.laravel.providers` would not have worked: for the root package that block
is never read. `package:discover` builds only from `vendor/composer/installed.json`.

**The schema lives in its own database**, `webgis`, on a `gis` connection the
package defines. Consequence: no foreign keys to `users`, since they would cross
a database boundary, so `gis_maps.owner_id` and `gis_map_user.user_id` are plain
integers the application layer validates.

| Root file | Why it had to change |
| --- | --- |
| `composer.json` / `.lock` | Path repository and the package requirement |
| `vite.config.js` | Two build inputs |
| `resources/views/base/layouts/plain.blade.php` | The body class and container wrapper became `@yield`s with their current values as defaults, so the editor can drop the centred container. Backwards compatible; login and the error pages render unchanged |
| `resources/views/base/inc/menu.blade.php` | The map icon, beside kitchensink, behind `@can('Access GIS')` |
| `database/seeders/UserSeeder.php` | `Access GIS` added to the Administrator role |
| `database/factories/UserFactory.php` | It omitted `username`, which is `NOT NULL UNIQUE` — the factory could not insert a row at all. Unrelated to this package, but it blocked every feature test |
| `phpunit.xml`, `tests/Pest.php` | The `Gis` test suite, and the testing databases |
| `lang/ms_MY.json` | Two strings |

**Tests run against real MySQL.** SQLite has no SRID-aware geometry and no
spatial index, so the suite needs MySQL 8 — which means it needs databases of its
own. `phpunit.xml` points at `basebackend_testing` and `webgis_test`; before
this session the suite would have run `migrate:fresh` against the working
`basebackend` database.

**Budgets:** initial transfer is 0.65 kB of JavaScript and 0.84 kB of CSS
(0.40 / 0.42 kB gzipped), plus the vendored Leaflet, which is loaded separately
and shared with the `latlng_picker` field. Nothing to compare against yet; S2 is
the first session with a real budget.

Deviation worth noting: Leaflet's zoom control and the back link both want the
top-left corner, so the control stack is pushed down 44 px (60 px on coarse
pointers) in the package stylesheet.
