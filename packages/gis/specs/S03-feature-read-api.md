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

- **The binary format exists for one reason: no parse step.** A 10,000-feature GeoJSON response is roughly 14 MB and ~400 ms to parse on a mid-range device; the same data in `GIS1` is roughly 4 MB and under 5 ms to view. If the client ends up copying or re-packing buffers, the format has failed its purpose.
- All arrays 8-byte aligned so `new Float64Array(buf, offset, len)` succeeds without copying.
- Encoding is content negotiation, never a separate endpoint or a `?format=` parameter.
- Binary is the default above 2,000 features; GeoJSON below, and for debugging.
- ~~`zoom` drives two things: the area cull threshold, and which geometry column is returned — below 16 `geom_simple`, 16+ `geom`.~~ **Superseded.** `geom_simple` and every other form of level of detail were removed in S2; below `edit_min_zoom` the zoom drives coordinate *quantisation* instead, which keeps every vertex and shortens each one. See *Quantised coordinates* below.
- **The area cull runs server-side and is not optional.** A zoom-12 bbox over Kuantan matches 159,654 features and must return ~12,900. Shipping the other 146,725 so the client can discard them would blow both the transfer and first-render budgets outright. The threshold is derived server-side from `zoom` and viewport latitude, never sent by the client, and the response reports `culled`, `returned` and `areaThresholdM2`.
- `minArea=0` suppresses the cull for export and attribute queries, where invisibility is irrelevant. It is not the default and the client never sends it for a viewport read.
- ~~The spatial index and `(layer_id, area_m2)` cannot both be used in one query.~~ **Superseded.** Neither is used: `ix_layer_read (layer_id, area_m2, minx, maxx, miny, maxy)` serves the whole `WHERE` and the `ORDER BY` with no filesort, and it is **forced**, because MySQL will never choose it once `geom` is in the select list. `ix_layer_area` was dropped as a leftmost prefix of it. The warning about a silent full scan was right, and this is how it was closed — see *The index the read forces* below.
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

150 ms is about 5,000 features.

**Follow-up: the planner's index choice was the larger problem, and it is now
made here.** `ORDER BY area_m2 DESC LIMIT n` invites MySQL to read
`ix_layer_area` backwards and stop early, which is right when the area
threshold is selective and ruinous when it is not. At zoom 16 the threshold
admits 385,510 of the layer's 672,109 features, so the scan walked most of them
looking for the few thousand inside a 3 km viewport — 1.8 s a layer, and 3-8 s
by the time both layers and the session lock were accounted for. Measured on
`lots`:

| Zoom | Planner | Forcing `ix_layer_bbox` |
| --- | --- | --- |
| 16 | 1.82 s | **0.16 s** |
| 14 | 1.09 s | **0.29 s** |
| 12 | 0.03 s | 0.38 s |
| 10 | 0.03 s | 0.74 s |

The plans trade places on whether the viewport or the threshold is the more
selective filter, and MySQL cannot tell: it estimates 770,775 rows for either,
having no statistics on these correlated columns. The controller decides
instead, from the viewport's share of the layer's extent
(`gis.read.bbox_index_max_share`, default 1%).

**That constant is tied to `min_area_px`** — raising the cull makes the
threshold more selective and moves the crossover down — so the two are tuned
together. The principled fix, if this becomes a nuisance, is to store an area
distribution per layer at import and estimate both row counts properly rather
than inferring one from geometry.

> **Superseded.** Both the heuristic and `gis.read.bbox_index_max_share` were
> removed once `ix_layer_read` existed — see "The index the read was always
> asking for" at the end of this file. The reasoning above still explains why
> MySQL cannot choose; the answer turned out to be one index that serves both
> filters rather than a rule for picking between two that each serve one.

After it, at `min_area_px = 32`, per layer:

| Zoom | `lots` | `usages` |
| --- | --- | --- |
| 18 | 119 ms | 105 ms |
| 17 | 137 ms | 157 ms |
| 16 | 180 ms | 243 ms |
| 14 | 350 ms | 366 ms |
| 12 | 16 ms | 68 ms |
| 10 | 10 ms | 17 ms |

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
  the feature array; the binary one put it in a header, which it could do
  because it was not streamed. **Superseded below** — the binary encoding now
  streams too, and the counts moved into a trailer frame.
- **The "binary above 2,000 features" rule lives in the client.** Deciding it
  server-side would mean counting the result before encoding it, and that count
  is the thing that was just removed. Negotiation is still by `Accept` and never
  by a `?format=` parameter.
- **Keyset paging and `206` are not built.** The cap makes them the next thing
  rather than this thing: a capped response is complete and says so, and nothing
  in the client yet asks for the remainder. The cursor over `(minx, id)` remains
  the design; it belongs with whatever first needs a second page.
- ~~**The GeoJSON encoding streams and the binary one does not.**~~ **Superseded.**
  See "Both encodings stream" below. The reasoning was sound — a `GIS1` header
  carries section offsets that are only known once every section is built — but
  the conclusion was wrong: the answer is to send several small documents rather
  than one large one.

### A bug worth recording

The first implementation returned the response without flushing, so PHP held the
entire body in its output buffer — "streaming" that streamed nothing. An
uncapped zoom-12 read then buffered tens of megabytes and, with `cursor()`
buffering the result set on the MySQL side as well, the request simply never
returned. Both are fixed: the stream flushes every chunk, and the cap bounds the
result set.

### Tests

23 feature tests across the two encodings: the byte layout (magic, version,
section offsets, 8-byte alignment of every float section, declared length,
sentinels closing both index arrays), coordinates written longitude-first as
little-endian doubles, and the two encodings asserted to agree on ids, areas and
coordinates for the same query. Plus the cull at three zooms, `minArea=0`, the
cap, the ordering, attribute suppression, simplified-versus-real geometry, and
the parameter validation.

## Both encodings stream, 100 features at a time

Added after the fact, on top of the session above.

The binary encoding is now a **sequence of framed `GIS1` documents** rather than
one: a kind byte, a little-endian length, and a payload, ending with a trailer
frame carrying the `cull` object. Each feature frame is a complete document of
at most `gis.read.stream_chunk` features, so the client parses and paints each
as it lands. The GeoJSON encoding was already one streamed document and only
changed its flush interval to match. The media type changed with the shape:
`application/vnd.gis.features+gis1-stream`.

The trailer exists because `returned`, `capped` and `smallestReturnedM2` are
only known once the last row has been read, and headers are written before the
first. That is the same argument that put the GeoJSON counts after the features;
it now applies to both.

### What it changed on the client

- `data/stream.js` reassembles frames out of whatever pieces the network
  delivered, copying each payload into a buffer of its own. That copy is not a
  missed optimisation: a `GIS1` document is read by pointing a `Float64Array` at
  an offset inside its buffer, which requires the buffer to begin where the
  document does.
- `data/accumulator.js` grows one layer's arrays a chunk at a time, rebasing
  `ringStarts` and `featStarts` onto what is already held and rewriting each
  sentinel rather than appending it. **The geometry object's identity never
  changes** — the renderer and the spatial index both hold a reference and
  re-read its fields, so growth replaces arrays on the same object.
- `SpatialIndex.append()` bulk-loads the new range into the existing tree, so
  the hit test stays correct while the map is still filling in.
- `GisRenderer.appendGeometry()` extends the `Path2D` objects already built
  instead of rebuilding them. This is the one that matters: see below.

### Results

Measured in Chrome against the imported cadastre. The layer named `Gunatanah` here was the single land-use layer of the time; it has since been replaced by the two layers `Gunatanah Semasa` and `Gunatanah Zoning` (specification §12), and the figures below are left as they were measured. `Gunatanah` at zoom 12 with
the usual quarter-viewport padding — 23,215 features, 233 frames, 9.0 MB.

| | |
| --- | --- |
| Full path rebuild, 23,215 features | 7.4 – 12 ms |
| Appending one 100-feature chunk | 0.0 – 0.6 ms |
| Spatial index, 232 appends | 17 ms total |
| Parse, 233 frames | 73 – 121 ms total (0.3 – 0.5 ms a frame) |
| Warm draw (the pan case, unchanged) | 0.0 – 0.2 ms |

**Appending rather than rebuilding is what makes this viable.** Replacing the
layer per chunk would rebuild a growing path 233 times: at roughly 8 ms a
rebuild that is about 1.9 seconds of main-thread work for one read, against
about 23 ms spent appending. `Path2D` cannot remove a subpath, so an append is
only valid while the cache it extends is still the right one; a zoom during the
read invalidates the whole set and the next draw rebuilds it once.

Correctness was checked by reading the same viewport through both encodings and
comparing: the accumulated arrays hold the same 23,215 features, the same id sum
exactly, and the same areas to double precision.

### What streaming did not buy, and why

Time to first byte is **430 – 490 ms** against a total of **484 – 537 ms**. Most
of the wait is MySQL, not transfer: the read is `ORDER BY area_m2 DESC`, and
where the planner does not take `ix_layer_area` it sorts before it returns
anything — measured at 393 ms to the first row, 411 ms to the last. Streaming
moves the first paint from the end of the response to the first frame after
that, which is an honest 50 – 165 ms, not the several hundred it would be if the
query itself streamed.

So the win here is smaller than the mechanism deserves, and the thing standing
in front of it is the index choice already recorded as an open item —
`bbox_index_max_share` is tuned against `min_area_px`, and the principled fix is
a stored area distribution per layer. **Streaming is what makes that fix worth
making**: without it, removing the sort would only have moved bytes around.

### Tests

Five more feature tests over the framing (frame counts against a configured
chunk size, a header and a length per frame, the trailer's cull, the cull being
the same however the features were divided, and the cap landing mid-chunk), plus
seven Node tests over the frame reader and the accumulator — reassembly from
one-byte reads, payload buffer alignment, a truncated stream, and the
accumulated arrays asserted equal to what a single whole response would have
built.

## The index the read was always asking for

Added after the streaming work, because streaming exposed it.

With both encodings sending 100 features at a time, the bytes still arrived in
one burst: nothing for ~490 ms, then 9 MB in 63 ms. The flushes were real and
fired 234 times; they simply all fired at the end, because the row source was
not progressive. `ORDER BY area_m2 DESC` was being served by a table scan and a
filesort, so MySQL produced no first row until the sort finished.

### Why the planner could not get there

Neither half of the read's `WHERE` is selective. On `Gunatanah`, 615,373
features, at the zoom-12 threshold:

| predicate | rows | share |
| --- | --- | --- |
| `area_m2 >= 5816` alone | 227,660 | 37.0% |
| bounding box alone | 121,049 | 19.7% |
| both | 16,475 | **2.7%** |

MySQL has no cross-column statistics, so it sees two weak filters, declines both
indexes and scans. Forcing either of the old indexes only moved the pain:
`ix_layer_area` had to read a row per candidate to test the bounding box, and
`ix_layer_bbox` still had to sort.

### The index

```
ix_layer_read (layer_id, area_m2, minx, maxx, miny, maxy)
```

Leading with `(layer_id, area_m2)` means a backward scan is already in the
order the read wants — no filesort, so the first row is immediate. Carrying the
bounding box means index condition pushdown answers the viewport test inside the
index, so a row is read only for a feature that survives it. `ix_layer_area` is a
leftmost prefix of this and was dropped.

**It has to be forced.** The moment `geom` joins the select list the index stops
covering, and the optimiser goes straight back to the table scan — it cannot see
that pushdown will discard 97% of the entries before any lookup. This is the one
place in the read where the planner is overruled outright rather than advised.

### Results

Per-zoom, geometry in the select list, `Gunatanah`. "first row" is unbuffered
time to the first row; the planner column is what shipped before this change.

| zoom | plan | filesort | rows | total | first row |
| --- | --- | --- | --- | --- | --- |
| 12 | planner | yes | 16,475 | 395 ms | 368 ms |
| 12 | `ix_layer_bbox` | yes | 16,475 | 279 ms | 250 ms |
| 12 | **`ix_layer_read`** | **no** | 16,475 | **96 ms** | **0.4 ms** |
| 14 | planner | no | 13,462 | 105 ms | 1.0 ms |
| 14 | **`ix_layer_read`** | no | 13,462 | 117 ms | 0.6 ms |
| 16 | planner | no | 8,239 | 113 ms | 0.7 ms |
| 16 | **`ix_layer_read`** | no | 8,239 | 123 ms | 0.6 ms |
| 18 | `ix_layer_bbox` | yes | 194 | 70 ms | 69.5 ms |
| 18 | **`ix_layer_read`** | no | 194 | 87 ms | 0.6 ms |

`ix_layer_read` is the only plan that never sorts. Where it loses on total time
it loses by 10–17 ms and wins the first row by 69–368 ms, which for a streamed
response is the number that matters.

End to end, the zoom-12 viewport read of 23,215 features over both layers:

| | before | after |
| --- | --- | --- |
| time to first byte | 428 – 504 ms | **95 – 169 ms** |
| whole response | 484 – 567 ms | **148 – 257 ms** |

Where the remaining time goes, for the same read: 48 ms to filter, 378 ms to
fetch 6.0 MB of geometry, 20 ms to encode. The geometry fetch is the payload and
is not going anywhere; it is now spread across the response instead of preceding
it.

### What is left

Time to first byte is ~95 ms rather than ~1 ms because `cursor()` runs over a
buffered PDO connection: `execute()` does not return until the whole result set
is in PHP memory, so the first `echo` waits for the last row. Unbuffered would
close that gap, but it has to be scoped to this one read and restored in a
`finally` — no other query may use the connection while the cursor is open, and
under Octane that connection is shared. Worth doing; not done here.

## Shrinking the response, and making a pan additive

Three changes, after the index work above.

### Coordinates are quantised below the editing zoom

GIS1 is version 2. Flag bit 1 marks quantised coordinates, the header's old
`reserved` word carries the exponent, and each ordinate becomes a `uint32`
holding `round((lng + 180) * 10^e)` — or `+ 90` for latitude. The bias is what
makes it unsigned, and unsigned is what makes it portable: PHP's `pack()` has no
signed little-endian code, only the machine's own byte order, while every other
field in this format is explicitly little-endian.

**Not at or above `edit_min_zoom`.** There a vertex can be dragged and sent
back, so a read that had rounded it would write the rounding into storage, and
each edit would move the vertex again. Full precision where geometry can
round-trip, shortened where it can only be looked at.

The conversion runs once over the whole accumulated blob in `encode()`, so
`appendRing` stays the pure byte copy the format was built around. Over 1.7
million ordinates: unpack 26 ms, scale 34 ms, repack 12 ms. Per ring it would
pay PHP's call overhead 38,000 times for the same arithmetic.

On the client it costs nothing extra. The projection pass over every vertex was
already there and already calls a `log` and a `tan`; undoing the bias adds a
multiply and a subtract. It is the one place the format allocates — a `uint32`
section cannot be widened in place — so the expanded coordinates travel back
from the worker as a second transferable buffer.

`geom_simple` is **not** used by this encoding — the column no longer exists. Simplification drops vertices
and changes the drawn shape; quantisation keeps every vertex and shortens each
one. They are alternatives, and this encoding takes the second.

### gzip was never on

nginx's default `gzip_types` is HTML and JSON, so the binary encoding shipped
uncompressed for the whole of S3. Adding
`application/vnd.gis.features+gis1-stream` and `application/geo+json`
compresses it to **0.45** of its size. This is deployment configuration, not
repository configuration: a new environment loses it silently, and the only
symptom is a response three times larger than this file claims.

### A pan asks only for the edge

The read accepts `held=minx,miny,maxx,maxy`, a box the client already holds
every feature for, and leaves out anything whose bounding box **meets** it.

Meets, not is contained by. A feature straddling the old boundary was returned
by the previous read, because that read asked for everything intersecting its
box too. Excluding on intersection is what makes the two responses disjoint, and
disjoint is what lets the client append without deduplicating by id.

All four bounding-box columns are in `ix_layer_read`, so the exclusion resolves
inside the index next to the viewport test — it costs no row read to reject a
row, and the plan still has no filesort.

The client compacts to the padded area on **every** additive read. That is not
an optimisation, it is the invariant: what is held must equal what `loaded`
claims, or a pan back across older leftovers asks for features it already has —
and two identical rings in one `Path2D` filled `evenodd` cancel into a hole
rather than showing as extra ink. Keeping the leftovers as a cache would mean
deduplicating by id and an unbounded working set.

`held` is not sent after a capped response, which dropped features inside the
box it covers.

### Results

Same zoom-12 viewport, `Gunatanah`, measured in Chrome. "On the wire" is
`encodedBodySize`, so it is what actually crossed the network.

| | on the wire |
| --- | --- |
| before any of this | 8.98 MB |
| after quantisation and gzip | **2.55 MB** |

A pan sequence from that view, a third of a viewport at a time:

| | features returned | on the wire |
| --- | --- | --- |
| initial load | 20,827 | 1.48 MB |
| pan west | 2,595 | **0.19 MB** |
| pan west | 1,354 | **0.09 MB** |
| pan west | 3,389 | **0.24 MB** |
| pan back east | 12,155 | 0.83 MB |

Panning back costs more because compaction had already dropped that ground —
the price of holding exactly what is claimed rather than caching. Against the
server directly, a quarter-viewport pan goes from 17,316 rows and 6.32 MB of WKB
to **841 rows and 0.36 MB**.

Correctness after four pans: **zero duplicate ids**, and the held set returns to
20,827 features at the original view — the same count a fresh full read gives.
The spatial index tracked it exactly, so compaction leaves no stale items.
