# S9b — Spatial and attribute query

**Depends on:** S9
**Specification:** §7 (query endpoint), §14 (query panel), §8 (map control panel)
**Gate:** every relation returns correct results with `EXPLAIN` showing an index; an over-broad query refuses rather than running slowly.

## Goal

Answer "which features satisfy these conditions" against 4.3M features, from a panel in the map control panel.

## Why this is v1

An earlier draft ran query client-side against the rbush index with a 5,000-candidate cap, and deferred the endpoint to v2. That was tenable when a map held a few thousand hand-drawn features. Against the imported cadastre it is not: a zoom-12 viewport alone holds 159,654 candidates, so the cap would refuse almost every real question. **A cap that rejects the normal case is not a feature.**

## In scope

- `POST /api/geo/layers/{layer}/query`
- Relations: intersects, within, contains, crosses, touches, disjoint
- `where` clauses over attributes, using the generated columns from S9
- `bufferMetres`, computed by `GeometryService` before the predicate runs
- `return`: ids, count or features, the last honouring the same `Accept` negotiation as a feature read
- The query panel in the map control panel, which is now a section at the foot of the layers sidebar rather than an overlay on the map (specification §8)
- Client-side short-circuit for trivially small queries, on a `capabilities` threshold

## Out of scope

Search — the text box that finds a named thing — is a different control with a different job (S5 builds the control panel shell, S9 the attribute index behind it). Do not merge them.

## Deliverables

```
packages/gis/src/Http/Controllers/Api/QueryController.php
packages/gis/resources/js/ui/query-panel.js
packages/gis/resources/js/map/select/query.js
```

## Constraints that apply here

- **MySQL cannot buffer geographic geometry.** The buffer is computed by `GeometryService` (S7) before the predicate runs. There is no SQL-only path.
- MySQL's spatial index **cannot be combined with other index conditions**, which is why the redundant `minx/miny/maxx/maxy` columns exist (§6). Attribute predicates ride B-tree indexes, the spatial predicate the R-tree. Verify with `EXPLAIN` which one the planner chose, and whether it was the right one.
- Queries whose candidate set exceeds **200,000 features are refused** with `query_too_broad`, not run.
- The client short-circuits only genuinely small queries. The threshold comes from `capabilities`, never a constant — a hardcoded number here drifts from what the server can actually do.
- Results become a selection, a new layer, or a filter on the current layer — the same three outcomes the UI in S9 already produces.

## Gate

- Each relation returns results matching a brute-force check on a subset small enough to verify.
- `EXPLAIN` shows an index for every relation and `where` combination.
- An over-broad query refuses in well under a second rather than running slowly.

## Tests

- Feature: each relation against known fixtures from the imported data.
- Feature: `EXPLAIN` index assertions across the parameter matrix.
- Feature: the 200,000-candidate refusal.
- Feature: buffered query agrees with a client-side Turf buffer plus predicate on a small input.

## Results

Measured against the real land-use layer, 2.5 million features:

| Query | Result |
| --- | --- |
| Intersects a small viewport | 8,002 examined, 7,986 matched, 557 ms |
| The same, plus `gunatanah_kategori = Komersial` | 8,002 examined, 441 matched, 466 ms |
| Intersects a box covering the state | **refused in 413 ms** — "That area holds 2,081,109 features; the limit is 200,000" |
| Attribute filter with no area at all | **refused in 237 ms** — 2,499,632 features |

**Every query runs in two passes, because the two indexes cannot be combined.**
MySQL will not use its R-tree and a B-tree in one plan, which is the whole
reason the redundant bounding-box columns exist (§6). So a candidate pass runs
over `layer_id` and the four box columns — served by `ix_layer_read` through
index condition pushdown — and the exact predicate runs over what survives.

### The counting had to be fixed before the ceiling worked

The first version put the attribute predicates in the **candidate** pass, which
made the count measure nothing. An attribute filter matching few rows produced
a small count from a scan of the whole layer, so the ceiling waved through
precisely the query it exists to refuse: an attribute-only query over 2.2
million rows reported 72,000 "candidates" and took 1.8 seconds to say so.

The count is now over the indexed filters alone. Attribute predicates run in
the second pass beside the exact spatial test, which is where the expensive
half belongs. The reported figure is named `examined` and means what it says —
the same 8,002 with and without a filter, because the index admitted the same
rows either way.

The refusal message says **"zoom in or draw a smaller area"** and does not
mention the filter, because an attribute predicate cannot narrow that count:
it is not indexed. §6 describes promoting a marked field to a generated
column, which is what would change that; suggesting a filter would help before
then is advice that does not work.

### `ST_Crosses` is asymmetric, and is asked both ways

By OGC a LINE crosses a POLYGON and the polygon does not cross the line. Every
relation here reads with the feature as the subject — a feature within the
shape, a feature containing it — and the same reading of `crosses` returns
nothing at all when somebody draws a line across a cadastre and asks which lots
it crosses. Crossing is a symmetric idea in everything except the argument
order, so both orders are asked, and a test pins both the positive and the
negative case.

### Not built

- **Generated columns for indexed attributes** (§6, S9's schema work). Until
  they exist an attribute predicate is a scan bounded by the ceiling rather
  than by an index, which is why an attribute-only query over a large layer is
  refused rather than slow.
- **A drawn query shape.** The panel uses the current viewport, which is the
  only shape the user has already expressed without drawing one. Drawing one is
  a drawing tool feeding this panel and belongs with the rest of the selection
  work.
- **The client-side short-circuit** for trivially small queries. The threshold
  would come from `capabilities`; nothing yet measures whether the round trip
  is worth avoiding, and guessing at it is how a hardcoded constant drifts from
  what the server can do.
- **Turning a result into a new layer or a filter.** It becomes a selection;
  the other two outcomes belong with S9's selection rendering.
