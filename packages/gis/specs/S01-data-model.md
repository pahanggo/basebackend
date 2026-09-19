# S1 — Data model

**Depends on:** S0
**Specification:** §6 (whole section), §5 (axis order footgun), §12 (v1 seeding)
**Gate:** axis order round-trips; `EXPLAIN` confirms the spatial index is used by the viewport query.

## Goal

Every table and the geometry cast. Data arrives in S1b, from the PLANMalaysia ArcGIS services.

## In scope

- Migrations for `gis_maps`, `gis_layers`, `gis_map_layer`, `gis_features`, `gis_measurements`, `gis_map_user`
- `GeometryCast`, the only place geometry is converted in either direction
- Eloquent models, relationships, factories
- The `EXPLAIN` harness later sessions reuse

## Out of scope

Generated columns for attribute filters (S9 creates them from `attr_schema`), the API (S3), soft-delete sweeps.

## Deliverables

```
packages/gis/
  database/migrations/*_create_gis_*_tables.php
  database/factories/{Map,Layer,Feature}Factory.php
  src/
    Models/{Map,Layer,Feature,Measurement}.php
    Casts/GeometryCast.php
```

DDL is given in full in specification §6. Do not paraphrase it — the `NOT NULL`, the SRID restriction and the redundant bbox columns are all load-bearing.

## Layers are two tables, not one

`gis_layers` holds what a layer **is** — name, kind, style, `attr_schema`, extent, feature count, `owner_map_id`. `gis_map_layer` holds where it **sits in one map** — `parent_id`, `sort_key`, `visible`, `opacity`, zoom range, `access`.

The split exists because a layer appears in many maps. `owner_map_id` NULL marks a global layer: the cadastral base imported in S1b exists once and is placed read-only everywhere. `gis_map_layer.parent_id` references another *placement*, not a layer, since the tree is per-map structure.

Each table carries its own `version`. Reorder and visibility guard on the placement's; name, style and schema guard on the layer's.

## The two footguns — read §6 before writing a migration

1. **Axis order.** MySQL reads SRID 4326 as latitude-longitude; every GeoJSON file and every GIS tool is longitude-latitude. Mixing them puts geometry in the wrong hemisphere **with no error raised**. Every read and write passes `'axis-order=long-lat'`.

   `ST_GeomFromText`, `ST_AsText`, `ST_AsBinary` and `ST_GeomFromWKB` must not appear anywhere outside `GeometryCast`. Add the `grep` assertion to the test suite in this session, not later.

2. **Spatial index preconditions.** The column must be `NOT NULL` and SRID-restricted or MySQL silently creates no index. Never assume it worked — assert it.

## Gate

- A geometry written through the cast and read back is identical, and lands in the right hemisphere.
- `EXPLAIN` on the viewport query reports the spatial index, not a full scan. This is an assertion in a test, not a manual check.
- `gis_features.area_m2` exists with its `(layer_id, area_m2)` index. The whole area-cull design in §4 rests on it, and adding it later means rewriting 4.3M rows. (S3 widened that index into `ix_layer_read`; `ix_layer_area` was dropped.)
- `gis_layers` and `gis_map_layer` are separate from the first migration. **This is the one structural decision that cannot be deferred**: layers are shared between maps (§8), so identity and per-map placement must be separate tables. Retrofitting means migrating live rows and rewriting every tree query.

## Tests

- Unit: `GeometryCast` round-trips point, line, polygon, polygon-with-hole, multi-part.
- Feature: axis order survives write → read, asserted against known coordinates in Malaysia.
- Feature: `EXPLAIN` asserts spatial index usage on the bbox query.
- Architecture: the `grep` assertion that raw spatial SQL functions appear only in `GeometryCast`.

## Notes

There is no synthetic fixture. Real data arrives in S1b and every later budget is measured against it — that decision came out of profiling the source cadastre, which showed the original assumed profile (10,000 features averaging 40 vertices) wrong in both directions.

## Results

Done. Six tables in the `webgis` database, the cast, five models, four factories,
and 27 passing tests.

**Both footguns are asserted, not assumed.**

*Axis order.* A point written through the cast and read back is
`POINT (103.326 3.8077)` — Kuantan, in Malaysia. Written without
`axis-order=long-lat` MySQL reads it as latitude 103.3, which does not exist, and
raises nothing. The test asserts longitude between 100 and 105 and latitude
between 1 and 7, so a silent flip fails loudly.

*Spatial index.* `SHOW INDEX` reports `sx_geom` as `SPATIAL`, the column is
`NOT NULL`, and `ST_GEOMETRY_COLUMNS.SRS_ID` is 4326. `EXPLAIN` on the viewport
query over a 1,600-row grid reports `key = sx_geom`, `type = range`.

**What MySQL actually hands back.** A plain column read returns MySQL's internal
format: a 4-byte little-endian SRID followed by standard WKB — and those WKB
bytes are byte-identical to `ST_AsBinary(geom, 'axis-order=long-lat')`. So the
storage order is longitude-latitude even though `ST_AsText` without the option
reports latitude first.

Sniffing that prefix is subtler than it looks. A bare WKB point begins
`01 01 00 00 00`, whose first four bytes read as a plausible SRID, so the cast
checks for bare WKB *first* — byte-order flag plus a geometry type in range —
and treats everything else as prefixed. Getting this backwards cost one test,
which is why both read paths are now asserted to agree.

**Geometry is interpolated, not bound.** MySQL will not take a placeholder inside
`ST_GeomFromText`'s options argument, so `GeometryCast::literal()` builds the
call as a string. That is safe only because the WKT is generated by `WktWriter`
from parsed coordinates and then checked against a character class that admits no
quote; anything that failed to parse never reaches it. The test asserts the
generated call, and the architecture test asserts that
`ST_GeomFromText`, `ST_AsText`, `ST_AsBinary` and `ST_GeomFromWKB` appear in
`GeometryCast` and nowhere else under `packages/gis/src`.

**Two corrections to the plan.**

`HasFactory::newFactory()` carries no return type, so a `newFactory(): ?Factory`
on the base model is a fatal signature conflict — and PHP reports it as a silent
exit rather than through the test runner. Factory redirection moved into a
`HasGisFactory` trait that aliases the original.

The models needed a `RefreshDatabase` that knows about two connections.
`migrate:fresh` drops the default connection's tables and then runs every
migration, including this package's, which target the GIS connection — so the
second run finds the GIS tables still standing. `Gis\Testing\RefreshesGisDatabase`
wipes the GIS connection first and transacts both.

**Not built here:** generated columns for attribute filters (S9), and there is
still no data — S1b imports it from the PLANMalaysia services
configured.

| Gate | Result |
| --- | --- |
| Geometry round-trips identically | Point, line, polygon, polygon-with-hole, multipolygon |
| Lands in the right hemisphere | Asserted against Kuantan |
| `EXPLAIN` picks the spatial index | `key = sx_geom`, `type = range` |
| `area_m2` with `(layer_id, area_m2)` | Present; the cull test shows the surviving set shrinking as zoom falls |
| Identity and placement separate from the first migration | `gis_layers` and `gis_map_layer`, separate `version` on each |
