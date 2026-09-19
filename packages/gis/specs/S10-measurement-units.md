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

## Results — units, coordinates and the scale bar (S10a)

**Partly done.** The arithmetic, the four coordinate formats and the scale bar
are built. The measurement TOOLS — the ones that save an annotation — are not.

| Format | Round-trip error at Kuantan |
| --- | --- |
| Decimal degrees | exact |
| Degrees, minutes, seconds | 0.62 m (the format rounds to 0.1″, which is 3 m) |
| UTM | 0.28 m (rounded to whole metres) |
| MGRS, 5 digits | 1.15 m (rounded down on both axes) |
| MGRS, 3 digits | within its own 100 m square |

Every format round-trips to within its own rounding, which is the property that
matters: a user types what is on the paper in front of them, and reading it
back has to give the same place. All four go through one parser, tried in order
of how distinctive each shape is, and it refuses rather than guesses.

**The go-to box used to carry its own parser** for decimal and DMS, with a note
that UTM and MGRS "arrive with the measurement work". They have, so it
delegates now — two parsers for one box is one too many. Its one good rule was
kept and moved into the shared module: a value beyond ±90 can only be a
longitude, which settles the order of a pasted pair without guessing.

**Units are per quantity, not per system.** A survey office measures distance
in metres and land in acres in the same breath, and a global toggle would be
wrong half the time. `{ system: 'si', area: 'rai' }` is a supported
preference, and rai, rood, nautical miles and chains are there because the
cadastre is still partly written in them.

**The scale bar measures at the map's CENTRE latitude**, which is where the
reader is looking. Leaflet's own control measures along the top edge of the
container, and on a tall viewport away from the equator those differ
noticeably. Two bars sharing one edge, SI above imperial, each rounded to 1, 2
or 5 times a power of ten — a bar labelled "137 m" is arithmetic the reader has
to do; one labelled "100 m" is a ruler. Verified at 200 m / 500 ft.

Updating it on `move` alone left it a zoom level behind: during Leaflet's zoom
animation `containerPointToLatLng` still answers for the projection the map is
leaving. It listens for the settle as well now.

### Still to build in S10

- **The measurement tools**: distance, area, radius, diameter, bearing and
  feature info, saved as annotations in `gis_measurements`. The table, the
  commands (`measurement.create/update/delete`) and all the geodesy they need
  already exist — what is missing is the tools themselves and the list that
  manages them.
- **The units preference, persisted per user.** The module takes preferences
  and nothing yet stores them; the coordinate readout remembers its format in
  `localStorage`, which is the right home for a per-reader preference but is
  not the same as `ui.units` per §11.
