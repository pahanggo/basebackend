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

_Fill in when complete._
