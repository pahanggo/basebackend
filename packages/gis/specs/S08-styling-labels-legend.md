# S8 — Styling, labels and legend

**Depends on:** S5, S2
**Specification:** §10 (whole section)
**Gate:** paint-state ceiling respected; full repaint < 16 ms.

## Goal

Data-driven symbology that stays fast because it resolves to a small number of paint batches. May split in two: styling, then labels and legend.

## Already built

**Fill and line colour are editable from the layer's row menu, shipped in S5b.**
A layer nobody can recolour is hard to tell from the one beneath it, and the
tree had the row menu already. They write `layer.setStyle` — whole-object
replacement, as this session's styles do. Extend that, do not build a second
path to the same column.

**There is no `fillOpacity` and this session must not add one back.** Layer
opacity is the single transparency control and the renderer applies it as
`globalAlpha` per layer. A second alpha in the style multiplied it, which gave
two controls for one visible property and a slider that could not reach opaque
(specification §10).

**Categorized styling shipped in S5d, as sublayers.** A layer splits by one
attribute, every distinct value becomes a toggleable row in the tree with its
own opacity and colours, and the renderer batches one path per geometry type
per class. Do not build a second categorized mode beside it — extend
`map/style/classify.js` and the `classification` document.

Three things about it constrain this session:

- **The classification is PLACEMENT state, not part of the style object**, which
  is what the specification used to say. A style write refuses a locked layer
  and every imported layer is locked, so the only layers worth classifying were
  the only ones that design could not reach (specification §6). The graduated
  and rule-based modes have the same problem and want the same home.
- **A class's opacity multiplies the layer's**, as one more level of the chain
  that already runs group → layer. That is not the `fillOpacity` above; do not
  remove it for resembling it.
- **The paint loop keys paths by geometry type AND paint slot.** A new mode
  supplies a different slot map and paint table, and needs no renderer change.

## In scope

- Style JSON per layer, resolved to a paint batch key at draw time and cached per feature
- Modes: single, graduated, rule-based, heatmap — **categorized is done** (S5d)
- Classification methods: equal interval, quantile, natural breaks (Jenks), standard deviation, manual
- ColorBrewer ramps with a colour-blind-safe filter
- Built-in sprite sheet, plus **custom SVG marker upload** through `POST /images` with `purpose=marker`
- Labels: field or template string, placement, collision, priority, zoom thresholds
- Auto-generated legend

## Out of scope

Attribute schema editing (S9). Print-layout legend (S14) — but the legend built here is what that reuses.

## Deliverables

```
packages/gis/resources/js/
  map/style/{resolve.js,classify.js,labels.js}
  ui/{style-panel.js,legend.js}
```

## Constraints that apply here

- **Batching is why this stays fast.** Graduated and categorized styles resolve to a small number of distinct paint states; features are grouped by resolved style so canvas state changes are minimised and paths accumulate into one `Path2D`. A rule-based style producing more than **64 distinct paint states** raises a performance warning.
- Labels are skipped entirely during pan and zoom, then drawn on `moveend`. Collision uses a grid index over label bounding boxes; lower-priority labels are **dropped, not displaced**.
- Classification runs client-side on the loaded feature set. Warn when the visible set is a subset of the layer — a quantile computed on a viewport is not a quantile of the layer, and silently pretending otherwise produces a misleading map.
- **Uploaded SVG markers are rasterised server-side to PNG at 1x and 2x, and the SVG is never served to a browser.** An SVG is an XML document that can carry `<script>`; serving user-supplied SVG from an origin holding a session is stored XSS. Rasterising discards everything that is not pixels. Requires Imagick with SVG support — where it is absent, refuse the upload with a clear message rather than serving the SVG.
- The style panel is the most input-dense UI in the package and will be verbose in jQuery. That cost was accepted when lit-html was rejected (§3). Do not introduce a templating library to make this session easier.
- Legend entries omit hidden layers and respect tree order and group nesting.

## Gate

| Metric | Budget |
| --- | --- |
| Full feature repaint with styling resolved | < 16 ms |
| Distinct paint states per layer | ≤ 64, warned above |

Compare against the S2 baseline at each of the three pinned zooms — this session adds work to the paint path and the delta is the number that matters.

## Tests

- Harness page: each classification method against known datasets, including edge cases (all-equal values, single feature, nulls).
- Browser: screenshot regression per style mode on the imported data. `keluasan` on "Lot" is the natural graduated demo; the categorized one, `gunatanah_kategori` on "Gunatanah Semasa", is already covered by S5d.
- Browser: label collision drops the lower-priority label and never overlaps.
- Browser: the paint-state warning fires above 64.

## Results

_Fill in when complete._
