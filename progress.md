# GIS build progress

A running log for Zulfa. Newest session at the top. Written as I go, so the
bottom of each section is the most recent thing I learned.

**Where the real detail lives:** each session's own file under
`packages/gis/specs/`, in its *Results* section, and the settled decisions in
`.ai/rules/gis.md`. This file is the summary and the list of things I could not
decide for you.

---

## Open questions for you

Nothing is blocked on these — I picked the least surprising option in each case
and kept going. They are here because you may disagree.

| # | Question | What I did | Where |
| --- | --- | --- | --- |
| 2 | A circle is stored as a **polygon** of 72 segments. MySQL has no circle type and `geom` is one column, so there is nowhere else for it to go — but the centre and radius are then not exactly recoverable. §7's GIS1 type 4 anticipates carrying them in the attribute tail; nothing writes that yet. | Stored as a polygon and raised it here. | S6a |
| 1 | `gis.read.min_area_px` is 1 and `max_features_per_response` is 1,000,000. At those values a zoom-12 viewport returns ~25,000 features from `Lot` alone against a 10,000 design target. They look like S3 verification settings that were never restored. | Left them alone. Retuning moves the §19 budgets, which are a hard gate. | spec §23 |

I left a map called **Scratch drawing tests** in your list. It is mine, for
testing — delete it whenever you like, or tell me to.

---

## Done

### S6a — Drawing, live readout, validation

Six tools in the toolbar over the canvas: point, line, polygon, rectangle,
circle, freehand. Every one also takes typed numbers, and both paths end in the
same functions — a shape drawn and a shape typed are byte-identical, which is
the only way to be sure they agree.

**The measurement had to be right, not just reasonable.** §11 budgets
client/server disagreement at 0.1%. Two corrections were needed and both were
found by testing round trips rather than formulas:

- Area by spherical excess was **0.44% high** against MySQL's `ST_Area`, at
  every scale, consistently. Projecting each vertex to its *authalic* latitude
  first is exact; worst case is now 0.006%.
- Bearing and distance disagreed by **0.15°** because distance was ellipsoidal
  and bearing was a great circle. Nothing failed — a point placed at "142.7 m
  on 63°15'" just did not measure back to 63°15'. They come from one solution
  now.

Verified end to end: a polygon drawn by pointer read out 67.7131 ha while
drawing and stored 677131.32 m². A typed 250 × 120 m rectangle stored
30,000.008 m².

Validation sits inside `GeometryInput::parse`, which every write reaches the
database through, so no write path can skip it. Auto-fixes (close the ring,
drop duplicate vertices, normalise winding) apply silently; blocks
(self-intersection, too few corners, out-of-range coordinates) refuse and name
the cause.

**S6 split**, at the seam its own file predicted. S6b has vertex editing,
snapping, holes and multi-part, the clipboard, and the conflict-resolution
panel.

### S5c — Image overlays

Commit below. Upload a scanned plan and line it up over the map by dragging its
four corners — plus a fifth handle that rotates all four together, which you
asked for mid-session.

- `POST /api/geo/images` is the only multipart write in v1. Upload and layer
  creation stay two steps so the command endpoint remains JSON, atomic and
  replayable.
- The transform is **projective, not affine**: each corner moves alone, which
  an affine fit cannot do without dragging a corner you did not touch.
  Rendered as a CSS `matrix3d` on an `<img>`, so the GPU warps it and a corner
  drag costs no canvas work at all.
- **Rotation happens in screen pixels, not degrees.** A degree of longitude is
  about a tenth shorter than a degree of latitude here, so turning the stored
  coordinates would squash the image as it turned.
- Measured: one command per gesture however many pointer moves it takes, zero
  feature reads, and a reload reproduces the warp exactly.

**Found on the way:** `Storage::url()` returns an absolute URL built from
`APP_URL` — the first upload came back pointing at `basebackend.phgg.link`
while the page was on `basebackend.test`. That URL is stored and read back on
every load, so it is made root-relative now, per the rule already recorded for
URLs inside JSON.

**Not built: numeric corner entry.** §13 lists it as the keyboard-accessible
path to the same adjustment. I left it for S11, which owns the accessibility
pass — the keyboard equivalents for every pointer gesture are better built
together than one per session. Noted as a decision, not an oversight.

I removed the test overlay I created on `pahang-baru` (layer 14). Your own
`md_lipis` overlay on map 13 is untouched.

### S5d — Sublayers (categorised layers)

Commits `72399eb`, `3b44ab3`.

A layer splits by one of its feature properties; each distinct value becomes a
row under it with its own checkbox, opacity and fill/line colours, and the rows
can be dragged to reorder.

**The decision that mattered.** The specification said classification belongs in
the layer's `style`, written by `layer.setStyle`. It cannot: that op refuses a
**locked** layer, and all nine imported layers are locked — so the only layers
worth splitting were the only ones that design could not reach. It is also
layer-level, so splitting the shared cadastre in one map would split it in
everyone's. I moved it to the **placement**, beside `visible` and `opacity`, and
amended §6, §7, §10 and §16 rather than following them.

**Filtering is client-side.** No index exists inside `properties` and
`ix_layer_read` has no room for one, so a per-class server filter would scan
once per visible class. Each feature carries a one-byte class index instead.
Zoom 12 over Kuantan: 15.33 MB plain, 15.49 MB classified, 41.39 MB if it had
asked for the whole attribute document. Toggling a class costs **zero** feature
reads and paints in 15–22 ms.

**Three bugs found by looking, none of which raised an error:**

- `evict()` calls `replaceGeometry` after every read that drops a feature, and
  that rebuilt the slot entry from a literal — discarding the classification, so
  a split layer reverted to its base colour on the first pan. (You reported
  this.)
- PHP encodes an empty style as `[]`, and `[].fill` is `Array.prototype.fill` —
  a function. So `?? fallback` never ran and the leftovers swatch painted
  nothing.
- **The tree stacked upside down.** The shared feature canvas sorted paint order
  ascending, so the *second* row drew over the first: `Lot` was burying every
  land-use colour under a sheet of pink. `panes.js` had reversed tree order
  since S5b and said so, but panes only stack groups, tiles and overlays. You
  approved the fix; it changes how existing maps look wherever two vector
  layers overlap.

