# S6 — Drawing and vertex editing

**Depends on:** S4, S2
**Specification:** §9 (whole section), §16 (coalescing)
**Gate:** validation suite passes; vertex drag < 8 ms.

## Goal

Draw and edit geometry by pointer *and* by typed numbers. May split in two: drawing, then vertex editing and snapping.

## In scope

- Tools: point, line, polygon, rectangle, circle, freehand
- Numeric entry for every tool — bearing and distance, coordinate lists, width/height/rotation, centre and radius
- Live readout while drawing: segment length, bearing, total, enclosed area
- Vertex editing: drag, insert at midpoints, delete, marquee-select, whole-feature move/rotate/scale
- Snapping with ranked candidates and per-type toggles
- Polygon holes, multi-part explode and combine, winding normalisation
- Client and server validation
- Clipboard: copy, cut, paste-in-place, paste-at-cursor, cross-layer paste

## Out of scope

Geometry operations — buffer, union, difference (S7). Measurement as a saved annotation (S10), though the live readout here shares `lib/units.js` with it.

## Deliverables

```
packages/gis/resources/js/map/
  draw/   edit/   snap.js
packages/gis/src/Validation/GeometryValidator.php
```

## Constraints that apply here

- **Pointer-only drawing is insufficient.** This tool is for surveying, planning and cadastral work, where exact dimensions are the entire point. Every tool takes typed numbers as a first-class path, not an afterthought.
- Handle rendering lives on the edit canvas, so a vertex drag never triggers a feature repaint. If dragging repaints features, the canvas separation from S2 has been broken.
- Snap candidates come from the spatial index within a pixel tolerance, then are ranked: vertex, midpoint, nearest-on-edge, intersection, grid. `Alt` suppresses. Self-snapping is allowed for closing rings and excluded otherwise.
- Tolerances differ by pointer type: 10 px fine, 18 px coarse.
- A drag gesture is one undo step, coalesced at 500 ms.
- Winding order is normalised on save — exterior counter-clockwise, holes clockwise, per RFC 7946.
- **Client validation is a UX affordance, never the guarantee.** The server repeats every check. Auto-fixes (close ring, drop duplicate vertices, drop zero-length segments) apply silently; blocks (self-intersection, fewer than three distinct vertices, vertex count over 50,000, out-of-range coordinates) highlight the cause.

## Gate

| Metric | Budget |
| --- | --- |
| Vertex drag frame time | < 8 ms |
| Hit-test for handle grab | < 5 ms |

Plus the full validation table from §9 behaving as specified on both sides.

## Tests

- Harness page: every validation rule, auto-fix and block.
- Harness page: snapping ranks candidates correctly and respects toggles.
- Browser: draw each geometry type by pointer and by numeric entry, asserting identical results.
- Browser: vertex drag is one undo step, not one per pointer move.
- Feature: server rejects geometry the client would have blocked, independently.

## Results

_Fill in when complete._
