# S1b — Import from the bencana database

**Depends on:** S1
**Specification:** §12 (v1 data), §6 (`area_m2`), §4 (area culling)
**Gate:** 1.4M features imported; the area cull returns ~12,900 at zoom 12; `EXPLAIN` clean on the culled viewport query.

## Goal

Get real cadastral data into `gis_features`, so S2 is gated against the actual workload rather than a synthetic guess.

## Why this is not the v2 import pipeline

v2's import (S12) is a milestone because files are the hard part: sniffing formats, negotiating CRS, streaming parsers, validation reports, rejects. **None of that applies here.** The source is a MySQL table in the same server, already `POLYGON NOT NULL SRID 4326`, already spatially indexed, already clean. This is a chunked `INSERT ... SELECT` with a cast, not a pipeline.

## Prerequisite

**The `bencana` connection does not exist yet.** `config/database.php` defines `sqlite, mysql, mariadb, pgsql, sqlsrv, kitchensink` — add a sixth alongside them. `kitchensink` is the precedent for a second MySQL connection in this application; follow its shape and give the credentials read-only access to `bencana`, since this command never writes there.

## The source

| Table | Rows | Becomes | Attributes |
| --- | --- | --- | --- |
| `bencana.lots` | 672,112 | Layer "Lot" | `upi`, `negeri`, `daerah`, `mukim`, `seksyen`, `no_lot`, `keluasan` |
| `bencana.usages` | 746,104 | Layer "Gunatanah" | `lot_upi`, `kod_gtn`, `gunatanah1` |

Measured profile, which is what S2 is gated against:

| | `lots` | `usages` |
| --- | --- | --- |
| Avg vertices | 6.7 | 11.1 |
| Max vertices | 320 | 7,886 |
| Under 500 m² | 41% | — |
| In a zoom-12 viewport | 159,654 | ~270,000 |
| Drawn after 4 px² cull | 12,929 | — |

Extent is roughly 101.33–104.21 E, 2.50–4.74 N.

## In scope

- `php artisan gis:import-bencana`, chunked, resumable, reporting progress
- A second database connection to `bencana`, read-only
- Computing `minx/miny/maxx/maxy`, `area_m2`, `vertex_count`, `geom_simple` per row
- Creating the two layers as **global layers** (`owner_map_id` NULL) with their `attr_schema`
- Layer extent and `feature_count` rollups

## Out of scope

Any form of ongoing sync. This is one-off per environment: once imported, features belong to this application and are edited here. If re-import against a changed source becomes a requirement, that is a change-detection design, not a flag on this command.

## Deliverables

```
packages/gis/src/Console/ImportBencanaCommand.php
packages/gis/config/gis.php          the bencana connection name
```

## Constraints that apply here

- **Axis order is the thing to get right, and it is already right.** `bencana` stores coordinates in MySQL's native EPSG 4326 order, latitude-longitude, which is correct for the column definition. Verified by reading a row both ways: the default `ST_AsText` gives `POLYGON((2.646 103.431, ...))` and `ST_AsText(geom,'axis-order=long-lat')` gives `POLYGON((103.431 2.646, ...))`, which is what this package wants. Read with the option; never assume the default.
- No reprojection, no CRS prompt, no validity repair expected — but assert validity anyway and report anything that fails rather than importing it silently.
- **Chunked inserts, 500–1,000 rows per statement, inside a transaction.** `Eloquent::create()` in a loop over 1.4M rows is forbidden here as firmly as it is in S12.
- `area_m2` is computed geodesically at import and is the column the whole area-cull design depends on (§4). `keluasan` exists on `lots` and can be cross-checked against it, which is a free validation of both.
- `geom_simple` is computed at import, not on read.
- Points and lines get `area_m2 = 0`; there are none in this source, but the column contract holds.
- **The two layers are created once for the deployment, not once per map.** `owner_map_id` is NULL and every map places them `read` through `gis_map_layer` (§8). Importing per map would mean 1.4M rows per map, which is exactly what the layer split exists to prevent.

## Gate

- Both layers import completely, with counts matching source row counts minus anything explicitly rejected and reported.
- A zoom-12 viewport query over Kuantan returns ~12,900 features after the area cull, from ~159,654 candidates.
- `EXPLAIN` on the culled viewport query shows an index, not a full scan — and the session records **which** index the planner chose, since the spatial and `(layer_id, area_m2)` indexes cannot both be used.
- `area_m2` agrees with `keluasan` within tolerance across a sample.

## Tests

- Feature: a known `upi` round-trips from `bencana` to `gis_features` with coordinates in the right hemisphere and the right order.
- Feature: chunking imports the full row count without exhausting memory.
- Feature: `area_m2` versus `keluasan` agreement across a sample.
- Feature: the culled viewport query returns the expected count at zoom 12, 14 and 16.
- Feature: both layers are global (`owner_map_id` NULL) and placing them in a second map writes one `gis_map_layer` row, not features.

## Results

_Fill in when complete._
