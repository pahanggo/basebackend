# GIS

The GIS web editor: map, layers, features, geometry.

Everything here is specified before it is built. **`specs/` is the source of
truth** — `GIS Web Interface — Technical Specification.md` for architecture, one
`S00`–`S15` file per build session, and `specs/README.md` for the order. Read
`.ai/rules/gis.md` before touching anything in this package.

## How it is wired in

The package is autoloaded from the root (`Gis\` → `packages/gis/src`), the same
way `packages/backpack/*` is — there is no path repository and no
`composer install` inside this directory. `GisServiceProvider` is registered in
`config/app.php`; the root `composer.json`'s `extra.laravel.providers` is not
read for the root project itself.

Client assets build through the root `vite.config.js`, which lists
`packages/gis/resources/js/main.js` and `resources/scss/gis.scss` as inputs.
Leaflet is **not** one of them: it is the vendored UMD build at
`public/packages/leaflet/`, loaded as the global `L` before the bundle.

## Its own database

The package's schema lives in a separate database on the `gis` connection
(`config/database.php`), defaulting to `webgis`. Nothing in this package
touches the default connection.

Two consequences:

- **No foreign keys to `users`.** `gis_maps.owner_id` and `gis_map_user.user_id`
  cross a database boundary, so they are plain integers and the application
  layer is what keeps them honest.
- **Migrations still run from `php artisan migrate`.** They are registered by
  the provider and each one resolves `config('gis.connection')`, so the schema
  goes to `webgis` while the `migrations` bookkeeping table stays in the
  application database.

## The cron entry this package needs

Three things here expire: soft-deleted maps and layers after 30 days, rendered
exports after 24 hours (v2), and overlay images orphaned when a layer's restore
window lapses. `gis:sweep` does all three and is idempotent.

**The application has no scheduler of its own.** The deployment needs:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

If that entry is declined, nothing breaks and storage grows: soft-deleted rows
are never purged, so the 30-day restore window becomes indefinite retention, and
orphaned overlay images are never reclaimed.

## GEOS

Constructive geometry runs through `geosop` (GEOS 3.11+), not a PHP extension.
`brew install geos` on macOS, `libgeos-bin` (or equivalent) on the server. Where
the binary is absent the API reports `capabilities.geos: false` and refuses
server-side operations rather than returning a plausible wrong answer — GEOS is
planar and unit-agnostic, so a buffer computed in the wrong coordinate system
looks fine and is not.
