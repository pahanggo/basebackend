# S8 — Styling, labels and legend

**Depends on:** S5, S2
**Specification:** §10 (whole section)
**Gate:** paint-state ceiling respected; full repaint < 16 ms.

## Goal

Data-driven symbology that stays fast because it resolves to a small number of paint batches. May split in two: styling, then labels and legend.

## In scope

- Style JSON per layer, resolved to a paint batch key at draw time and cached per feature
- Modes: single, categorized, graduated, rule-based, heatmap
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
- Browser: screenshot regression per style mode on the imported data. `gunatanah_kategori` on "Gunatanah Semasa" (14 values) is the natural categorized demo; `keluasan` on "Lot" the natural graduated one.
- Browser: label collision drops the lower-priority label and never overlaps.
- Browser: the paint-state warning fires above 64.

## Results

_Fill in when complete._
