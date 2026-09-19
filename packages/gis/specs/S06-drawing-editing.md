# S6 — Drawing and vertex editing

**Depends on:** S4, S2
**Specification:** §9 (whole section), §16 (coalescing)
**Gate:** validation suite passes; vertex drag < 8 ms.

## Goal

Draw and edit geometry by pointer *and* by typed numbers.

**It split**, at the seam this file already named. **S6a is done**: the tools,
the live readout, numeric entry and validation on both sides. **S6b remains**:
vertex editing, snapping, holes and multi-part, the clipboard, and the
conflict-resolution panel.

## Where the tools mount

The toolbar over the canvas is empty and waiting. S5b moved everything else out
of it — choosing a map sits beside the map's name, the sidebar toggle beside
the way out, isolate with the tree's actions — precisely because none of those
act on the canvas and these do. It is `#gis-app .gis-toolbar`, currently
`hidden`.

The conflict-resolution panel is also this session's: S4 built
`store/conflicts.js` and S5b left the queue pausing and holding its commands on
a 409, with the user told once. That is the safe half; resolving is the other
half, and it belongs here, where editing makes a real conflict likely.

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

## Results — S6a (drawing, readout, validation)

| Metric | Result |
| --- | --- |
| Client area against MySQL `ST_Area`, polygon drawn by pointer | 67.7131 ha read out, 677131.32 m² stored — agreement to 6 significant figures |
| Typed 250 x 120 m rectangle, area stored | 30,000.008 m² against 30,000 asked for |
| Rectangle side lengths, typed against measured | exact to 1 mm at 100 m, and at 50 km |
| Circle vertices against the typed radius | exact to 1 mm |
| Worst distance error, `destination` then `distance` | 7.4e-6 m |
| Worst bearing error, same round trip | 5.6e-6 degrees |

**The area formula had to be corrected twice before it agreed with the
server.** Specification section 11 budgets client/server disagreement at 0.1%.
Haversine distance is out by a fraction of a percent, so distance is Vincenty.
Area was worse: spherical excess on the equal-area sphere — the version most
references give — measured **0.44% high** against `ST_Area` at every scale from
a house plot to a district, consistently, because near the equator the
ellipsoid's local area element is about that much smaller than the sphere's.
Projecting each vertex to its **authalic latitude** first is exact for area and
brought the worst case to 0.006%.

**Bearing and distance now come out of one solution.** They did not at first:
the distance was Vincenty and the bearing a great circle, and they disagreed by
0.15 degrees. Nothing failed — a point placed at "142.7 m on 63°15'" simply did
not measure back to 63°15'. Caught by testing the round trip rather than the
formula.

**A rectangle is built by walking bearings from a corner, not by combining two
corner coordinates.** A rectangle in degrees is not a rectangle on the ground:
its north edge is shorter than its south edge because a degree of longitude
shrinks with latitude. The dragged path measures the sides the drag implies and
then rebuilds through the same function the typed path uses, which is what
keeps "drawn" and "typed" the same object rather than two that nearly agree.

Worth knowing: a geodesic quadrilateral **cannot** have four right angles and
equal opposite sides at once. A 50 km square's far side differs from its near
side by 19 m. That is geodesy, not a defect, and it is asserted in the tests so
nobody removes it as one.

**Validation lives in `GeometryInput::parse`**, which every write reaches the
database through — so no write path can be added that forgets it.
Self-intersection is asked of MySQL's `ST_IsValid` rather than computed in PHP:
it is the predicate every later spatial operation is judged by, and a geometry
this accepted which `ST_Intersects` then refused would be a bug with no visible
cause.

### Not built in S6a, and why

- **Vertex editing, snapping, holes, multi-part, clipboard** — S6b. The seam
  this file predicted turned out to be the right one: everything above is about
  producing geometry, everything remaining is about changing it.
- **The conflict-resolution panel** — S6b, with the editing that makes a
  conflict likely.
- **UTM and MGRS entry for the point tool.** §9 lists them; the parsers belong
  to S10, which owns coordinate display and input, and writing them twice is
  worse than writing them once a session later. Decimal degrees work now.
- **A circle is stored as a polygon** of 72 segments. MySQL has no circle type
  and `geom` is one column, so there is nowhere else for it to go — but the
  consequence is that a circle's centre and radius are not recoverable exactly
  from what is stored. §7's GIS1 type 4 anticipates carrying them in the
  attribute tail; nothing writes that yet. Raised rather than decided.

## Results — S6b

_Fill in when complete._
