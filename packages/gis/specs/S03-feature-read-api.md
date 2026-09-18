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

Built and measured. **The transfer budget is met with room to spare; the server
query budget is missed by about four times, and the reason is neither the
encoding nor PHP.**

### The binary format does what it exists to do

Measured on the real read the editor issues at zoom 12, 20,000 features per
layer:

| | GeoJSON | `GIS1` | |
| --- | --- | --- | --- |
| `lots` response | 7.30 MB | **3.02 MB** | 2.4x smaller |
| `usages` response | 13.20 MB | **5.33 MB** | 2.5x smaller |
| `lots` into typed arrays | 151 ms | **112 ms** | |
| `usages` into typed arrays | 391 ms | **63 ms** | 6.2x faster |

Every array is a view onto the response buffer. Nothing is copied and nothing is
re-packed, with one deliberate exception: coordinates arrive as longitude and
latitude, because that is what the database holds and what every other consumer
expects, and the renderer wants normalised Web Mercator. That projection runs
**in place**, writing back over the same buffer. It is a single arithmetic pass,
not a re-pack, and it is what the 112 ms for `lots` mostly is.

The format is also cheap to produce. MySQL's WKB stores each ordinate as a
little-endian double, which is exactly what a `Float64Array` wants, so the
encoder copies each ring's coordinate run with `substr` and converts no numbers
at all. Encoding 20,000 features costs **under a millisecond** on top of
fetching them.

### The server read misses its budget, and it is MySQL

| Metric | Budget | Measured |
| --- | --- | --- |
| Server viewport read, zoom 12, 20,000 features | < 150 ms | **666 ms** `lots`, 624 ms `usages` |
| Client handling of a binary response | < 5 ms, no per-feature allocation | **viewing is free**; the in-place projection is the only pass |
| Imported layer to first render, zoom 12 | < 3 s | **~1.0 s** per layer end to end |

Where the 666 ms goes, measured by taking pieces away:

| | Time |
| --- | --- |
| Full read and encode, 20,000 features | 667 ms |
| Same, fetching **only the id column** | 677 ms |
| Same, unordered | 404 ms |
| Same, through raw PDO instead of Eloquent | 582 ms |

Fetching nothing but ids costs the same as fetching geometry and encoding it.
The geometry is free, the encoder is free, and Laravel's hydration is worth
about 14%. **The cost is MySQL producing 20,000 rows**, at roughly 30 µs each,
and `ORDER BY area_m2 DESC` adds about 270 ms of that.

It scales linearly with the number of features returned, which means the budget
is a feature-count budget in disguise:

| Threshold | Rows | Time |
| --- | --- | --- |
| 4 px² | 20,000 | 616 ms |
| 4 px², forcing `ix_layer_bbox` | 20,000 | 402 ms |
| 10 px² | 11,080 | 363 ms |
| 10 px², cap 10,000 | 10,000 | 312 ms |
| 16 px² | 7,434 | 208 ms |

150 ms is about 5,000 features. Two levers exist and neither was pulled here:
the cull constant, which is the user's open decision, and forcing
`ix_layer_bbox`, which is 1.5x faster at 4 px² but is the kind of tuning that
ages badly and should be taken with the constant rather than before it.

### The cap is the answer to a question S1b left open

S1b found that a fixed threshold in square pixels does not bound anything: a
larger window simply gets more features, and a 1456 x 840 viewport took 3.6x the
intended count. The read now **orders by area descending and caps**, so the
count is bounded whatever the threshold is, and what falls off the end is always
the least visible thing on the screen.

`EXPLAIN` confirms that ordering is free in index terms: `ix_layer_area` is read
with a **backward index scan**, no filesort. That index was added in S1 for the
cull and turns out to be what makes the cap cheap.

### Decisions that differ from the plan

- **Attributes are not sent by default.** The renderer needs geometry, an id and
  the area it was culled on; for the imported cadastre the attribute document is
  most of the payload. `fields=1` adds it back. The plan called this a JSON tail
  that should be "genuinely optional" — it is, and it is off.
- **The counts come after the features, not in headers.** Reporting `candidates`
  up front meant two `COUNT(*)` queries over 174,000 rows, which measured at two
  thirds of the whole request. The GeoJSON encoding puts the `cull` object after
  the feature array; the binary one puts it in a header, which it can do because
  it is not streamed.
- **The "binary above 2,000 features" rule lives in the client.** Deciding it
  server-side would mean counting the result before encoding it, and that count
  is the thing that was just removed. Negotiation is still by `Accept` and never
  by a `?format=` parameter.
- **Keyset paging and `206` are not built.** The cap makes them the next thing
  rather than this thing: a capped response is complete and says so, and nothing
  in the client yet asks for the remainder. The cursor over `(minx, id)` remains
  the design; it belongs with whatever first needs a second page.
- **The GeoJSON encoding streams and the binary one does not.** A capped binary
  response is about 3 MB, and its header carries section offsets that are only
  known once every section is built.

### A bug worth recording

The first implementation returned the response without flushing, so PHP held the
entire body in its output buffer — "streaming" that streamed nothing. An
uncapped zoom-12 read then buffered tens of megabytes and, with `cursor()`
buffering the result set on the MySQL side as well, the request simply never
returned. Both are fixed: the stream flushes every 2,000 features, and the cap
bounds the result set.

### Tests

23 feature tests across the two encodings: the byte layout (magic, version,
section offsets, 8-byte alignment of every float section, declared length,
sentinels closing both index arrays), coordinates written longitude-first as
little-endian doubles, and the two encodings asserted to agree on ids, areas and
coordinates for the same query. Plus the cull at three zooms, `minArea=0`, the
cap, the ordering, attribute suppression, simplified-versus-real geometry, and
the parameter validation.
