# S7 — Geometry operations

**Depends on:** S6
**Specification:** §9 (geometry operations), §5 (GeometryService)
**Gate:** client and server produce the same result for the same input.

## Goal

Buffer, union, difference and the rest — client-side for small inputs, server-side above the vertex limit.

## In scope

- Turf on the client: buffer, union, difference, intersection, split, simplify, convex hull, centroid, point-on-surface
- `POST /api/geo/geometry/ops` routing to `GeometryService`
- `GeosGeometryService` on `brick/geo`, backed by `GeosOpEngine` (the `geosop` binary)
- Runtime routing from `capabilities`, not a hardcoded constant
- Simplify preview before commit

## Out of scope

Spatial query (S9b), which uses this session's buffer. Import-time simplification (S12) — same service.

## Deliverables

```
packages/gis/src/Geometry/
  GeometryService.php          interface
  GeosGeometryService.php
  PurePhpGeometryService.php
packages/gis/src/Http/Controllers/Api/GeometryOpsController.php
packages/gis/resources/js/map/ops/
```

## GEOS is planar — this is the whole session in one paragraph

`brick/geo` has no pure-PHP engine; it drives `geosop` (GEOS 3.15.0, installed). **GEOS treats coordinates as unitless Cartesian numbers.** Buffering `POINT(103.326 3.8077)` by 250 returns a polygon spanning longitude 353 and latitude −241 — it read 250 as degrees. Hand it lon/lat with a distance in metres and it returns a confidently wrong answer, which is worse than MySQL's refusal.

Every constructive operation therefore goes through:

```
ST_Transform(geom, <metric SRID>)   -- MySQL, 4326 -> metres
  → geosop bufferQuadSegs <d> 32    -- GEOS, metres in and out
  → ST_Transform(result, 4326)      -- MySQL, back to storage
```

- **32 quadrant segments, not the default 8.** Measured on a 250 m buffer at Kuantan: 8 segments gives 248.79 m and −0.647% area error, 32 gives 249.91 m and −0.046%. The default breaches §11's 0.1% client/server agreement, which is a test case.
- **Metric SRID is chosen per geometry** from centroid longitude: UTM 47N (32647) west of 102°E, 48N (32648) east. The data extent straddles 102°E, so neither can be hardcoded.
- **No PHP reprojection library.** MySQL does both transforms; `proj4php` never enters the dependency list. All ten registry SRIDs are present in `INFORMATION_SCHEMA.ST_SPATIAL_REFERENCE_SYSTEMS` and the 4326 round trip is exact.
- `geosop` is a **subprocess**: give it a timeout, a bounded input size, and WKT on stdin — never geometry interpolated into a shell argument.

## Constraints that apply here

- **MySQL cannot do any of this.** `ST_Buffer`, `ST_Union`, `ST_Difference`, `ST_Intersection` and `ST_Simplify` are Cartesian-only and error or emit invalid output on SRID 4326; `ST_MakeValid` does not exist. All constructive work happens in Turf or `GeometryService`. Do not reach for a SQL shortcut here — it will silently produce geometry in the wrong place.
- **None of this is on a hot path.** Buffer and union run when a user clicks a button, not on every pan. That is what makes the division workable and why a round trip is acceptable.
- Route by `capabilities.inlineOpVertexLimit` and `capabilities.geos` read from the bootstrap response, so a deployment without the GEOS extension degrades without shipping different client code.
- Bind `GeometryService` with a resolver closure. **Octane is enabled**: never inject the container, request or config repository into the constructor, and never accumulate static state.
- Buffers are geodesic, negative for inward.
- `GeometryService` is the migration path to PostGIS. Geometry is stored as standard WKB with identical SRID semantics, so a move later swaps one implementation rather than rewriting the application — keep the interface clean of MySQL specifics.

## Gate

- For inputs near the vertex limit, the client and server paths agree within **0.1%** (§11). Turf is geodesic, GEOS-through-projection is not exactly so — 32 quadrant segments and a correctly chosen UTM zone are what close the gap.
- With GEOS unavailable, the fallback either produces a correct result or refuses with a clear message — never a silently degraded one.

## Tests

- Feature: each operation through `GeometryService`, both implementations, against known fixtures.
- Harness page + feature pair: the same input through Turf and through the endpoint, compared.
- Feature: routing honours `capabilities`; an over-limit request from a client that ignored them is still handled correctly.
- Feature: a buffer computed near 102°E picks the right UTM zone, and a geometry straddling it is handled deterministically.
- Feature: geometry containing WKT-like text in a property cannot influence the `geosop` invocation.
- Architecture: no Cartesian MySQL spatial function appears anywhere in the package.

## Results

_Fill in when complete._
