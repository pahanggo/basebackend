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

### Still to build in S10 — as of S10a

- **The measurement tools**: distance, area, radius, diameter, bearing and
  feature info, saved as annotations in `gis_measurements`. Built in S10b
  below.
- **The units preference, persisted per user.** Still open; see S10b.

## Results — the measurement tools (S10b)

**Done.** Six tools, saved annotations, and the gate met against MySQL on real
geometry rather than a fixture.

### The gate

| | Client | MySQL | Divergence |
| --- | --- | --- | --- |
| Distance, 532 m | 532.23819 m | 532.23515 m | **0.00057%** |
| Area, 8.46 ha | 84,641.424 m² | 84,636.737 m² | **0.0055%** |
| Radius, 238 m | 238.34013 m | 238.34015 m | 0.0000096% |
| Diameter, 477 m | 476.68380 m | 476.68380 m | 0.00000025% |

Against a budget of 0.1%, the worst is fifty-five thousandths of one percent,
and these are measurements taken by hand over Kuantan rather than a synthetic
case. The latitude sweep from 0° to 70° is asserted separately, in
`MeasurementTest`, against `tests/fixtures/geodesic.json`.

**That fixture is the part worth explaining.** The PHP gate cannot call into
the JavaScript, and hardcoding the client's numbers in a PHP test makes them a
record of what the client used to do. So the figures live in one JSON file,
the PHP test compares MySQL against it, and a Node test recomputes every one of
them from `lib/measure.js` and fails if it has moved. Neither side can drift
without something going red.

### Decisions

**Three stored kinds, six tools.** `gis_measurements.kind` is an enum of what
the value MEANS — a length, an area, an azimuth — and radius and diameter are
both lengths. Which tool took it goes in `properties`, because it changes how
the annotation is read and not what the number is. The alternative was widening
an enum to record a gesture.

**A diameter is stored as the whole line through the centre**, not as
centre-to-edge with a doubled value. The invariant the module exists to hold is
that a stored distance IS the geodesic length of the stored geometry; a doubled
value would be a number nobody could check against the line beside it. And the
far end is computed by travelling the opposite bearing, not by reflecting the
coordinates — on an ellipsoid the reflection is not the same point, and at
these latitudes it is wrong by enough to fail the gate and right by enough to
look correct.

**`unit` holds the PREFERENCE, not the unit of `value`.** `value` is always
metres, square metres or degrees, and `kind` says which of the three. What is
stored beside it is `si`, `imperial`, `rai` — how the taker was reading at the
time, which is what lets a measurement read back the way it was written.

**Saved measurements paint on the overlay canvas**, which S2 created and
nothing had drawn to until now. They survive a pan, so not the edit canvas,
which is cleared per gesture; they are not features, so not the feature canvas,
whose paths are cached per layer and rebuilt per zoom.

**Visibility is one flag for the whole map**, as §11 asks. Per-row visibility
would be a second kind of hidden: a row you cannot see and a row that is not
there look identical on the map and different in the list.

**The tools live in the measurement panel, not the drawing toolbar.** They look
identical in the hand and are completely different in consequence — one writes
a feature into a layer, the other an annotation onto the map — and one strip of
buttons invites exactly the mistake of measuring a boundary and finding you
have edited it. The two sessions share the edit canvas's single painter slot,
so arming either disarms the other.

**Measurements ride along with the bootstrap, geometry and all.** The one place
that response carries coordinates, and the exception holds because a
measurement is never read by viewport — one outside the current view is still
something the reader needs to find in the list.
`gis.measurements.max_per_map` caps it at 500 and the client is told when it
was hit.

### Three things found while building it

- **`notify()` took an options object and eight call sites passed a bare
  string.** Destructuring a string gives an undefined title, so every one of
  those was an empty dialog where a message should have been, and none of them
  errored. It takes either now.
- **The toolbar's live readout carried its own conversion factors** — its own
  `/1000` and `/10_000` and its own DMS arithmetic — which §11 forbids in as
  many words. The scale bar carried its own foot and mile. Both delegate to
  `units.js` now, and there is a test that greps for every factor outside it:
  a foot defined twice is a foot that can differ once.
- **The measurement list was told about the store before the store was
  hydrated**, so it was empty on every load while the measurement sat in the
  bootstrap and in the database. Nothing errored, and the only symptom was a
  list that looked correct for a map with nothing in it.

### Still to build in S10

- **The units preference, persisted per user.** It is read from
  `localStorage` under `gis.units`, which follows the browser rather than the
  account. §11 calls it `ui.units` and puts it on the user; there is nowhere
  on the server to put it yet, and a preference that pretends to follow the
  account while following the browser is worse than one that says which it is.
  The live drawing readout does not take the preference at all yet — a user
  working in acres still draws in hectares and sees acres once it is saved.
- **Editing a saved measurement's geometry.** The command takes a new `geom`
  and re-measures; nothing in the UI sends one. The vertex editor works on
  features, and pointing it at an annotation is a session of its own.
- **Measurements in exports and share links**, which §11 asks for and which
  belong with S14 and S15 rather than here.
