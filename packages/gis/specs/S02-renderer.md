# S2 — Rendering engine

**Depends on:** S1b
**Specification:** §4 (whole section), §19 (budgets)
**Gate:** **hard gate.** §19 budgets met against real `bencana` data at zoom 12, 14 and 16, on the reference device.

## Goal

Render 1.4M cadastral features at 60fps. This is the one decision every later session depends on, and the only session whose failure means rethinking the architecture rather than fixing a bug.

**Treat the budget as a gate, not a task.** If it misses, stop and say so rather than proceeding to S3.

## What the workload actually is

Profiled from `bencana` before this session was written, and it is not what a vector renderer usually assumes:

| Zoom | Candidates in view | Drawn after 4 px² cull | Vertices drawn |
| --- | --- | --- | --- |
| 12 | 159,654 | 12,929 | ~87,000 |
| 14 | 55,729 | 11,819 | ~79,000 |
| 16 | 5,025 | 4,990 | ~33,000 |

Two things follow, and both invert the usual priorities:

- **Simplification is nearly useless here.** Parcels average 6.7 vertices. A quadrilateral cannot be simplified — reducing it yields a triangle, then a line. Vertex count was never the cost.
- **Area culling is the mechanism.** 41% of lots are under 500 m², which at zoom 12 is 0.35 px². Dropping everything below 4 px² holds the drawn set near 13,000 at every zoom, because zooming out shrinks parcels below the threshold faster than it admits new ones.

So **the hot path is culling, not painting**: at zoom 12 the index returns 159,654 candidates to produce 12,929 drawn features. Optimise the query and the cull loop; the paint is comfortably within budget once they are right.

Simplification still earns its place for the minority case it was meant for — `usages` reaches 7,886 vertices, and a large estate lot stays on screen at every zoom.

## In scope

- Typed-array geometry storage, GeoJSON discarded after parse
- One `rbush` per layer, built in the worker
- Custom `L.Layer` subclass owning its canvases
- Three-canvas split: feature, overlay, edit
- **Area culling by zoom**, the primary LOD mechanism
- Visvalingam-Whyatt simplification for the large-polygon minority, precomputed in the worker
- `parse.worker.js` returning transferable `ArrayBuffer`s
- Style-batched painting into `Path2D`

## Out of scope

Styling configuration (S8 — here a single hardcoded style per geometry type is enough), selection UI (S9), editing handles (S6 builds on the edit canvas this session creates), labels (S8).

## Deliverables

```
packages/gis/resources/js/map/
  renderer.js  geometry.js  spatial-index.js  panes.js
  worker/parse.worker.js
```

## Constraints that apply here

- **Leaflet's own path layers are not used for features.** Leaflet creates one DOM node or `L.Path` per feature and hit-tests by scanning every layer on every pointer move — both linear in feature count, neither survivable at 10,000. Leaflet keeps only the few dozen interactive objects: vertex handles, in-progress geometry, measurement labels.
- **Geometry never enters the store.** The store holds metadata and style; the renderer owns coordinates. This keeps the store small enough to serialise into a bug report (§3).
- **The three-canvas split is not an optimisation, it is a requirement.** Without it every pointer move during a vertex drag repaints all 10,000 features. S6 and S9 both depend on it existing.
- Circles store centre and radius in metres, densified only at draw time.
- The worker stays dependency-free — no Turf, no Leaflet.
- Build the worker with `new Worker(new URL('./worker/parse.worker.js', import.meta.url), { type: 'module' })` so Vite emits it as its own chunk.

## Gate

Measured on the reference device (4-core mobile-class CPU, 4 GB RAM, 4x CPU throttle) against imported `bencana` data, **at zoom 12, 14 and 16** — zoom 12 is the stress case because of the candidate count:

| Metric | Budget |
| --- | --- |
| Sustained pan/zoom frame rate | ≥ 55 fps, no frame over 33 ms |
| Full feature repaint | < 16 ms |
| Index query + area cull, 160,000 candidates | < 10 ms |
| Hit-test (index query + exact test) | < 5 ms |
| Selection change repaint | < 8 ms |
| Peak JS heap with both layers loaded | < 250 MB |

## Tests

- Pest browser test driving load → pan → zoom → hit-test at each of the three zooms while collecting traces.
- Screenshot comparison against imported data for visual regression.
- Harness page: the area cull drops exactly the features below threshold, and none above it.
- Unit (via the harness page): the spatial index returns the same candidates as a brute-force scan on random queries.

## Notes

Record every number, not just pass/fail, and record them per zoom. S8 and S9 both add work to the paint path and will need this baseline to show what they cost.

If the budget misses, the likely remedy is server-side vector tiling, which §1 puts out of scope. That is a scope conversation to have immediately, not a thing to engineer around quietly.

## Results

_Fill in when complete. Include the full budget table with measured values._
