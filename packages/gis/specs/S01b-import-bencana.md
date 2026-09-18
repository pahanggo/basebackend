# S1b — Import from the bencana database

**Depends on:** S1
**Specification:** §12 (v1 data), §6 (`area_m2`), §4 (area culling)
**Gate:** 1.4M features imported; the area cull returns ~12,900 at zoom 12; `EXPLAIN` clean on the culled viewport query.

## Goal

Get real cadastral data into `gis_features`, so S2 is gated against the actual workload rather than a synthetic guess.

## Why this is not the v2 import pipeline

v2's import (S12) is a milestone because files are the hard part: sniffing formats, negotiating CRS, streaming parsers, validation reports, rejects. **None of that applies here.** The source is a MySQL table in the same server, already `POLYGON NOT NULL SRID 4326`, already spatially indexed, and almost clean — 11,638 rows fail `ST_IsValid`, which Results covers. This is a chunked `INSERT ... SELECT` with a cast, not a pipeline.

## Prerequisite

**The `bencana` connection does not exist yet.** It is defined in `packages/gis/config/database.php` alongside the `gis` connection and registered by the provider, so `config/database.php` gains nothing — see S0. Give the credentials read-only access to `bencana`; this command never writes there.

## The source

| Table | Rows | Becomes | Attributes |
| --- | --- | --- | --- |
| `bencana.lots` | 672,112 | Layer "Lot" | `upi`, `negeri`, `daerah`, `mukim`, `seksyen`, `no_lot`, `keluasan` |
| `bencana.usages` | 746,104 | Layer "Gunatanah" | `lot_upi`, `kod_gtn`, `gunatanah1` |

Profile as originally measured against the source. Results below re-measures it against the imported data, and the two agree except on max vertices, where this figure counted the exterior ring only:

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

Done. 1,406,578 features in two global layers, imported in about 8 minutes.

| | `lots` | `usages` |
| --- | --- | --- |
| Source rows | 672,112 | 746,104 |
| Imported | 672,109 | 734,469 |
| Rejected as invalid | 3 | 11,635 |
| Avg vertices | 6.5 | 9.4 |
| Max vertices | 676 | 15,709 |
| Under 500 m² | 40.6% | 47.5% |
| `geom_simple` written | 88,495 | 90,780 |

Storage: 673 MB of data and 216 MB of index for `gis_features`.

### The source is not as clean as the specification assumed

`ST_IsValid` rejects **11,638 rows** — 3 lots and 11,635 usages, 1.6% of that
table — almost all self-intersections. The specification expected none.

They are skipped, and their source ids are written to
`storage/app/gis-import-rejects-{lots,usages}.txt`. Repair is not attempted:
MySQL has no `ST_MakeValid`, and `GeometryService::makeValid` does not exist
until S7. Every imported feature carries its source row id under the `_src`
property, so those rows can be found and brought in later.

Everything else about the source is as described. Every row is SRID 4326 in
MySQL's native latitude-longitude order, read with `axis-order=long-lat`, and no
reprojection is needed.

### The cull works, and its constant is calibrated to a smaller screen

Measured on the real data, viewport 1456 x 840 px centred on Kuantan:

| Zoom | m/px | Drop below | `lots` candidates | `lots` drawn | `usages` drawn |
| --- | --- | --- | --- | --- | --- |
| 12 | 38.13 | 5,817 m² | 164,109 | 13,974 | 17,539 |
| 14 | 9.53 | 364 m² | 61,570 | 13,408 | 14,742 |
| 16 | 2.38 | 23 m² | 5,834 | 5,796 | 2,576 |

This reproduces the profiling the design rests on: candidates fall by 28x
between zoom 12 and 16 while the drawn set stays flat, because zooming out
shrinks parcels below the threshold faster than it admits new ones.

**But the drawn set is per layer, and the threshold is per pixel.** With both
layers visible at zoom 12 the renderer is handed 31,513 features, three times
the 10,000 design target in section 4. Raising `min_area_px` from 4 to 8 brings
`lots` to 11,699 on this viewport. The 4 px² constant was calibrated against a
smaller viewport than this one, and a fixed px² threshold does not hold a
constant count as the window grows. **S2 should decide** whether the threshold
becomes a target count the server solves for, or stays a constant that a larger
screen simply pays for. Left at 4 here rather than changed unilaterally.

### The viewport query should not use ST_Intersects

Three ways to ask for the same 13,974 features, all returning exactly that:

| Plan | Index chosen | Time |
| --- | --- | --- |
| `ST_Intersects` + `area_m2` | `sx_geom`, range, 170,906 rows | 1.69 s |
| Same, `FORCE INDEX (sx_geom)` | `sx_geom` | 1.66 s |
| `minx/maxx/miny/maxy` + `area_m2`, no spatial predicate | `ix_layer` | **0.48 s** |

So the answer to the gate's question — which index the planner chooses — is
`sx_geom`, and it chooses correctly. The finding is that it does not matter: a
geodesic `ST_Intersects` over 170,000 candidates costs more than four plain
double comparisons over the same rows. The redundant bbox columns earn their
storage exactly as section 6 predicted, and **S3's feature read should take the
bbox route**, leaving the exact geometry test to the client, which re-culls
against its real viewport anyway.

One caveat found while measuring: the two are not quite equivalent at the
viewport edge. MySQL's `ST_Intersects` on 4326 is geodesic, so the envelope's
edges bow away from constant-latitude lines — over a 55 km span, by about 2 m.
A handful of features near the boundary fall on different sides of the two
tests. The client pads its request, so this is a sliver, not a problem.

### Three things MySQL would not do

- **`ST_Envelope` is not implemented for geographic reference systems.** The
  bounding box is computed by reinterpreting the geometry as SRID 0 first —
  which is safe precisely because MySQL stores 4326 internally in
  longitude-latitude order, so X is longitude and Y is latitude.
- **There is no `ST_NumPoints` for a polygon**, and no way to loop over rings in
  an expression. Looping is not academic: one source row has 2,164 interior
  rings. The vertex count comes out of the WKB layout instead —
  `(length - 9 - 4 * rings) / 16` — which is exact, verified against
  `ST_NumPoints(ST_ExteriorRing(...))` on rows with no holes.
- **`ST_Simplify` is Cartesian only**, and free to emit invalid geometry. It
  runs through the same SRID 0 round trip, only for features above the zoom-12
  area threshold — the ones that are still drawn when zoomed out — and the
  result is kept only where it is still valid. That is 179,275 rows of
  1,406,578, not the whole table.

`area_m2` agrees with the source's own `keluasan` to 0.058% on average across a
1,112-row sample, worst case 1.31%, with 4 rows over 1%. That is a free
validation of the geodesic area *and* of the axis order: a swapped axis would
not land within 2% of a surveyed area.

### A defect the import itself found

The first run was interrupted partway. Re-running it appended rather than
resumed, leaving 1,108,085 features in a 672,112-row layer — silently, with
nothing to reveal it but a feature count that did not add up.

The spec called for a resumable command and the first implementation was not
one. It now refuses to import into a layer that already holds features unless
told `--fresh` or `--resume`, and `--resume` continues from the highest `_src`
already written. That is what the `_src` property is for; provenance was the
secondary benefit.

### Not done

`bencana` is on the same MySQL server as `webgis`, so the import is a
cross-database `INSERT ... SELECT` and rows never pass through PHP. The command
checks that precondition and refuses with an explanation rather than falling
back to a slow path, because there is no second deployment shape to serve yet.

| Gate | Result |
| --- | --- |
| Both layers import completely | 1,406,578 of 1,418,216, the difference reported and listed |
| ~12,900 drawn at zoom 12 from ~159,654 | 13,974 from 164,109 for `lots` |
| `EXPLAIN` shows an index, and which | `sx_geom`, range — but the bbox route is 3.5x faster |
| `area_m2` agrees with `keluasan` | 0.058% mean, 1.31% worst over 1,112 rows |
