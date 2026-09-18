# S10 — Measurement and units

**Depends on:** S6
**Specification:** §11 (whole section)
**Gate:** client and server agree within 0.1% across a latitude sweep from 0° to 70°.

## Goal

Measurement that is correct, which is a higher bar than measurement that displays a number.

## In scope

- Geodesic distance, bearing, length, area, perimeter, radius, diameter
- Tools: distance, area, radius, diameter, bearing, feature info
- Measurements persisted as annotation objects in `gis_measurements`, managed from the measurement tool
- Units module: SI/imperial toggle with per-quantity overrides, persisted per user
- Coordinate display and input: decimal degrees, DMS, UTM, MGRS
- The go-to-coordinate box in the map control panel, reusing those parsers
- Dual scale bar computed at the map centre latitude

## Out of scope

**Magnetic bearing, entirely.** The bearing tool reports true forward and back azimuth only. Malaysian declination is around 0.2°E — below the precision anyone reads off a map — and cadastral work uses true or grid bearings. No WMM table, no declination lookup, no `magnetic` unit option.

## Deliverables

```
packages/gis/
  resources/js/map/measure/
  resources/js/lib/{units.js,format.js}
  src/Commands/Measurement*.php
```

## Constraints that apply here

- **Measurements do not appear in the layer tree.** The tree is built from `gis_map_layer` rows and a measurement is not a layer; making one masquerade as a placement pointing at nothing would be special-cased in every tree query for no gain. They render on the overlay canvas and are managed from the measurement tool's own list — select, rename, hide, delete — with visibility all-or-nothing per map.

- **All measurement is geodesic on the WGS84 ellipsoid. Planar Web Mercator measurement is never used, in any code path.** This matters more than it first appears: Mercator area error scales as sec²(latitude), so a polygon measured planar at 45° overstates area by roughly 2x and at 60° by 4x. A measuring tool that does this is worse than no measuring tool, because the number looks authoritative.
- **Unit selection is presentation-only.** Every stored value, every API payload and every command is metres or square metres. Conversion lives in `lib/units.js` and nowhere else — if a conversion factor appears in a component, it is a bug.
- Measurements are saved annotations, not ephemeral overlays: they survive a reload, can be labelled, and are included in exports and share links. They are managed from the measurement tool, **not** the layer tree.
- Display precision adapts to magnitude — under 1 km shows metres with no decimals, above shows kilometres to two — and the user can pin a fixed precision.
- The scale bar is computed from geodesic distance at the map centre latitude, not at the equator.
- Server-side verification uses `ST_Distance`, `ST_Length` and `ST_Area` on SRID 4326, which MySQL computes with geographic semantics.

## Gate

Client and server measurement agree within **0.1%** across a latitude sweep from 0° to 70°. A larger divergence is a test failure, not a rounding note.

## Tests

- Harness page + feature pair: the same geometry measured on both sides across the latitude sweep.
- Harness page: unit conversion round-trips; no conversion factor exists outside `units.js` (architecture assertion).
- Harness page: coordinate format parse and render round-trip for DD, DMS, UTM and MGRS, including paste detection.
- Browser: each measurement tool produces a persisted annotation that survives reload.

## Results

_Fill in when complete._
