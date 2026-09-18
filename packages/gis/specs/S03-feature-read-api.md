# S3 — Feature read API

**Depends on:** S1b, S2
**Specification:** §7 (feature reads, binary encoding, paging), §19
**Gate:** viewport query p95 < 150 ms; seeded 10,000-feature layer to first render < 3 s.

## Goal

Get features from MySQL into the renderer's typed arrays without ever allocating an object per feature.

## In scope

- `GET /api/geo/layers/{layer}/features`
- The `GIS1` binary encoding, arrays 8-byte aligned
- GeoJSON via `Accept` negotiation at the same URL
- `bbox`, `zoom`, `fields`, `ids`, `cursor` parameters
- **Server-side area culling by zoom**, with the cull reported in the response
- Keyset paging with an opaque cursor, `206` above 20,000 features
- Client-side fetch that views the buffers and hands them to the renderer

## Out of scope

Writes (S4), spatial query by predicate (S9b), attribute filters (S9).

## Deliverables

```
packages/gis/src/Http/
  Controllers/Api/FeatureReadController.php
  Encoders/BinaryFeatureEncoder.php
packages/gis/resources/js/data/features.js
```

## Constraints that apply here

- **The binary format exists for one reason: no parse step.** A 10,000-feature GeoJSON response is roughly 14 MB and ~400 ms to parse on the reference device; the same data in `GIS1` is roughly 4 MB and under 5 ms to view. If the client ends up copying or re-packing buffers, the format has failed its purpose.
- All arrays 8-byte aligned so `new Float64Array(buf, offset, len)` succeeds without copying.
- Encoding is content negotiation, never a separate endpoint or a `?format=` parameter.
- Binary is the default above 2,000 features; GeoJSON below, and for debugging.
- `zoom` drives two things: the area cull threshold, and which geometry column is returned — below 16 `geom_simple`, 16+ `geom`.
- **The area cull runs server-side and is not optional.** A zoom-12 bbox over Kuantan matches 159,654 features and must return ~12,900. Shipping the other 146,725 so the client can discard them would blow both the transfer and first-render budgets outright. The threshold is derived server-side from `zoom` and viewport latitude, never sent by the client, and the response reports `culled`, `returned` and `areaThresholdM2`.
- `minArea=0` suppresses the cull for export and attribute queries, where invisibility is irrelevant. It is not the default and the client never sends it for a viewport read.
- The spatial index and `(layer_id, area_m2)` cannot both be used in one query. Check with `EXPLAIN` which one the planner chose and whether that was the right one — this is the single most likely place for a silent full scan.
- **Offset paging is not used.** Over a spatial query it degrades badly and can skip or duplicate rows when data changes mid-scan. The cursor is keyset over `(minx, id)`.
- Coordinates are longitude-latitude everywhere, no exceptions (§7).

## Gate

| Metric | Budget |
| --- | --- |
| Server viewport query, p95, zoom 12 over 160,000 candidates | < 150 ms |
| Imported layer to first render, zoom 12 | < 3 s |
| Client-side handling of a binary response | < 5 ms, no per-feature allocation |

## Tests

- Feature: binary response byte layout — magic, version, offsets, lengths, alignment.
- Feature: the same query in both encodings returns semantically identical data.
- Feature: paging visits every row exactly once, including when rows are inserted mid-scan.
- Feature: the cull returns the counts measured in S1b at zoom 12, 14 and 16, and `minArea=0` returns all candidates.
- Feature: `EXPLAIN` still shows the spatial index under every parameter combination.
- Browser: a fixture layer renders from a real response within budget.

## Notes

The `fields` parameter appends a JSON tail. Keep it genuinely optional — the common case is geometry and id only, and that case should transfer no JSON at all.

## Results

_Fill in when complete._
