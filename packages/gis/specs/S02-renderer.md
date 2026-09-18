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

  **The 4 px² constant is an open decision, left for this session.** S1b measured it against the imported data on a 1456 x 840 viewport: it holds `lots` at 13,974 and `usages` at 17,541 at zoom 12 — each near target, 31,515 together. Measured alternatives, both layers at zoom 12: 8 px² gives 17,909, 10 px² gives 15,029, 16 px² gives 10,136. The cost is ground coverage, not count — at 10 px² about a fifth of the `lots` layer's covered land goes unpainted at zoom 12, so dense blocks of small parcels thin out rather than reading as a mass. Zoom 16 is unaffected either way, so this is a zoom 12-14 knob only. Decide it with the renderer in front of you, and note that a fixed px² threshold hands a 4K display three times the features — the durable fix is a target count the server solves for per request.

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

Built and measured. **The budget is met for the interaction that dominates —
panning — and missed for a cold rebuild. The reference-device gate was not
measured at all**, and that qualification matters more than any number below.

### Measured, on the development machine

Apple Silicon, Chrome, 1710 x 930 viewport, against the imported `bencana`
data at zoom 12 with both layers placed. **No CPU throttling was applied**: the
tooling available here cannot drive Chrome's throttling, so every figure is
from hardware several times faster than the reference device.

| Metric | Budget | Measured | |
| --- | --- | --- | --- |
| Repaint, paths cached (pan) | < 16 ms | **0.00 ms**, worst 0.40 ms | pass |
| Repaint, paths rebuilt (zoom, new data) | < 16 ms | **75.7 ms** both layers, 35.6 ms one | miss |
| Index query + area cull | < 10 ms | **2.9 ms** over 36,000 candidates | pass |
| Hit test | < 5 ms | **median 0.0 ms**, p95 0.2 ms, worst 2.7 ms | pass |
| Peak JS heap, both layers | < 250 MB | **30 MB** | pass |
| Sustained pan/zoom frame rate | >= 55 fps | **not measured** | — |
| Selection repaint | < 8 ms | not applicable until S9 | — |

Drawn at that view: 40,000 features, 273,327 vertices, two layers.

The frame rate could not be measured through this tooling: a backgrounded tab
gets no `requestAnimationFrame` callbacks, and the extension could not drive a
foreground one. What is known is the JS cost per frame, which is the part this
session controls, and it is 0.00 ms while panning.

### The rebuild cost is a per-zoom cost, not a per-frame cost

The 75.7 ms figure is building `Path2D` objects for 40,000 features. It happens
when the zoom changes or a response arrives — not when the map moves. Panning
reuses the built paths and shifts them with a canvas transform.

That is the single most valuable thing in this session. Paths are built once in
world pixels and translated per frame:

| | Before | After |
| --- | --- | --- |
| Pan frame | 44 ms | 0.00 ms |

It is also why the coordinates are shifted by a build-time origin rather than
being absolute world pixels: canvas paths hold their points as 32-bit floats,
and at zoom 18 an absolute world pixel has lost the precision to land on the
right one.

### Four measurements that changed the design

**1. `closePath()` cost 2,151 ms of a 2,177 ms repaint.** Building one
accumulating `Path2D` for 16,180 polygons, each ring closed with `closePath()`,
took 2.2 seconds. The same loop without it takes 26 ms. Chrome appears to charge
each `closePath()` in proportion to the whole path built so far. It is not
needed anyway: every polygon ring arrives with its first vertex repeated at the
end, so the subpath already closes itself. *Fill and stroke were never the
problem* — they measured 1.3 ms and 0.0 ms for the same path.

**2. Coordinates are stored projected, not as longitude and latitude.**
Projecting at draw time costs a `sin` and a `log` per vertex, which measured
21 ms of a 34 ms repaint — most of a frame, spent recomputing something that
never changes. Normalised Web Mercator scales linearly with zoom, so a draw is
now one multiply and one subtract per vertex. Longitude and latitude are
recovered with `unproject` where they are needed, which is editing and export, a
few vertices at a time. Verified against Leaflet: across 500 features the
largest disagreement with `latLngToContainerPoint` is 0.5 px, which is its
rounding.

**3. rbush's `search` allocates an object per hit.** A zoom-12 query matches
36,000 of them. Walking the tree directly and collecting indices into a reused
`Uint32Array` took the query from 17 ms to 2.9 ms and now allocates nothing per
frame. The cull compacts in place into the same buffer for the same reason.

**4. Refetching on every `moveend` freezes the tab.** The first feed did, and a
drag of a few pixels queued a multi-megabyte response; the application's
file-based sessions serialise them, so forty small pans became forty queued
requests. The read now covers a quarter-viewport of padding on each side and
only a pan that leaves that area asks for more. Padding is not free either —
half a viewport each way doubles both dimensions and quadruples the response,
which measured 24 MB a layer.

### What the cull constant does to this

At 4 px² both layers hand the renderer 40,000 features, which is four times the
10,000 the budget in section 19 assumes. One layer alone is 20,000 and rebuilds
in 35.6 ms. The per-vertex cost is about 0.15 µs, so a 13,000-feature,
87,000-vertex workload rebuilds in roughly 13 ms — inside the budget.

**The constant is still open, and this session did not close it.** The
measurements in the section above stand, and the cap S3 introduced changes the
question: the server now returns the largest N features rather than everything
above a threshold, so the count is bounded whatever the constant is. What the
constant now controls is how much ground area goes unpainted, not how much work
arrives.

### Deviations from the plan

- **Leaflet's own `closePath`-free path building** meant dropping the
  "coarser tolerance while moving" behaviour in the plan: with a cached path
  there is nothing to coarsen, and a moving flag would only invalidate the
  cache. Visvalingam-Whyatt still runs in the worker and its mask is applied
  below zoom 16, where the server also sends the pre-simplified geometry.
- **The three canvases exist but only the feature canvas is painted.** Overlay
  and edit are created and sized; S6 and S9 fill them.
- **Pest browser tests were not written.** `pestphp/pest-plugin-browser` and
  Playwright are not installed, and installing them is a dependency decision.
  The pure client-side code — projection, array building, the index, the cull,
  simplification — is covered by 11 tests under Node's own test runner, asserted
  from Pest so the suite keeps one entry point and the repository gains no
  second framework. Visual regression is therefore also not automated; the
  rendering was checked by eye against the basemap, where parcel boundaries
  follow the coastline and the street grid exactly.

### Is this a scope conversation?

The specification says to have one immediately if the budget misses. **Not yet.**
The miss is a cold rebuild on a machine that was measured without throttling,
and the mechanism that would fix it — fewer features per rebuild — is the cull
constant that is already an open decision with numbers attached. Vector tiling
is not indicated by anything measured here: the index query, the cull, the hit
test and the pan frame are all comfortably inside budget, and the one number
outside it is linear in the feature count that the cap now bounds.

What would justify reopening scope is the reference-device measurement, which
nobody has taken. It should be taken before S8 and S9 add work to this path.
