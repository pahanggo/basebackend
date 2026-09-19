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
| 1 | `gis.read.min_area_px` is 1 and `max_features_per_response` is 1,000,000. At those values a zoom-12 viewport returns ~25,000 features from `Lot` alone against a 10,000 design target. They look like S3 verification settings that were never restored. | Left them alone. Retuning moves the §19 budgets, which are a hard gate. | spec §23 |

---

## Done

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

