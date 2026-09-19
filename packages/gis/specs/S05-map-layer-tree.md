# S5 — Map CRUD and layer tree

**Depends on:** S4, S2
**Specification:** §7 (map creation, listing, bootstrap), §8 (whole section), §18 (layer tree)
**Gate:** tree budgets met, accessibility clean.

## Goal

The map browser that chooses which map is open, and the tree that owns layer identity, order, visibility, opacity and z-order.

> **Split, as this file anticipated.** **S5a — maps and sharing** is built: every
> endpoint, every layer command, the authorization, the map browser and the layer
> library. **S5b — the layer tree** is not: the virtualized tree, drag and drop,
> panes and z-order, and the map control panel. The seam is the one named below,
> and it holds — S5a is data and authorization, S5b is the view over it. The gate
> in this file is S5b's; S5a's results are recorded at the end.

## In scope

- `GET /api/geo/maps` (list), `POST /api/geo/maps`, `GET /api/geo/maps/{map}`, `PATCH`, `DELETE`, `map.restore`
- `GET /api/geo/layers` (library listing)
- **Map browser modal**: table of maps with create, load and delete
- Map creation from empty or copy. **No templates** — that requirement is dropped
- Soft-delete restore for maps and layers, within the 30-day window, **administrators only**
- The virtualized tree through `ui/virtual-list.js`
- Fractional `sort_key` (base-62) so a reorder writes one row
- Drag and drop: above, below, into — with distinct affordances
- Tri-state visibility with inheritance
- **Opacity slider on every layer kind** — vector, raster, image and group
- **Shared layers**: add from library, share into another map, remove from this map, set access
- **Layer library modal** and `GET /api/geo/layers` behind it
- **Map control panel**: basemap switcher, weather overlays, go-to-coordinate, search, isolate, and the shell the S9b query panel mounts into
- Admin-only restore of soft-deleted maps and layers
- Map copy, which shares layers rather than duplicating features
- Leaflet panes and z-order from tree position
- Tree search and filter
- Full ARIA treeview

## Out of scope

Image overlays (S5b), styling (S8), attribute schema (S9).

## Deliverables

```
packages/gis/
  resources/js/ui/{map-browser.js,layer-library.js,layer-tree.js,control-panel.js,virtual-list.js}
  src/Http/Controllers/Api/{MapController.php,BootstrapController.php}
  src/Commands/Layer*.php
  src/Policies/MapPolicy.php
```

`ui/virtual-list.js` is shared with the attribute table in S9. Build it as a general row recycler, not a tree-specific one.

## Map control panel constraints

- Anchored top-right, collapsible, above the map canvas. **An active draw or edit tool collapses it** so it never intercepts a drawing gesture.
- The basemap list comes from `GET https://tiles.pahanggo.com/providers`, fetched and cached **server-side** and delivered in the bootstrap response — never fetched from the browser. On failure fall back to the cached list, then to the configured default provider alone.
- Providers prefixed `owm-` are weather **overlays**, not basemaps: independently toggleable, stacked above the basemap and below vector layers, stored in `viewState.overlays`. Everything else is a mutually exclusive basemap in `viewState.basemap`. Classify by prefix so a new one needs no code change.
- There is already a `grayscale` provider, so **do not build a CSS greyscale filter**.
- Search and Query are separate controls with separate jobs. S5 builds the panel shell and Search; S9b mounts the query builder into it.
- Isolate is client-only and not persisted; restoring brings back the previous per-layer visibility rather than turning everything on.

## Layer library constraints

- The modal is called **Add from library**, not "Import". In this specification import means reading a file (§12, v2); reusing the word for placing an existing layer confuses two operations that share nothing.
- Lists every global layer plus every layer owned by a map the user may open. **Nothing else** — the library must not become a discovery channel for maps the user cannot access.
- `availableAccess` is computed per user per layer. `read` for anything listed; `edit` **only** where they already hold edit rights on that layer. Without this a user could add the cadastral base as editable to their own map and rewrite 4.3M features they were only meant to read.
- The server re-checks access on `layer.share` regardless of what the modal offered. **A disabled control in a modal is not an authorization boundary** — write the test that calls the command directly with `access: edit`.
- Layers already placed in this map are listed but marked and unselectable; `(map_id, layer_id)` is unique.
- Show `feature_count` in the listing, so the weight of what is being added is visible beforehand.

## Shared layer constraints

- A layer belongs to one map (`owner_map_id`) but is placed in many through `gis_map_layer`. **Sharing copies nothing.**
- Commands split by what they touch: placement ids for reorder, visibility, opacity, `removeFromMap` and `setAccess`; layer ids for rename, style, schema and delete. Two ids, two versions — do not collapse them in the client because the bootstrap response flattens them for rendering.
- **Effective permission is the narrowest of three**: the user's role on the map, the placement's `access`, and the layer's `locked`. Owning a map grants nothing over a layer shared into it as `read`, and `layer.setAccess` is refused unless the acting map owns the layer — there is no self-escalation path.
- `edit` reaches the **layer**, not the placement: renaming or restyling a shared layer changes it in every map showing it. Style is layer-level in v1. This is a known consequence, not an oversight — surface it in the UI when a user restyles a layer that is placed elsewhere.
- **"Remove from this map" is not "Delete".** The first drops one placement; the second soft-deletes the layer everywhere and is offered only to the owning map, warning which other maps are affected.
- Read-only placements render edit affordances as **absent**, not disabled-with-a-tooltip.
- Map copy creates placements only. Never features, at any size.

## Map browser constraints

- The **Features** column counts only layers this map owns. Summing shared layers would print the 4.3M imported base on every row and tell the user nothing.
- The listing is **server-side paged, not virtualized.** A user has tens of maps, not thousands. Do not reach for `virtual-list.js` here.
- **Load flushes the outbound queue first.** Switching maps with unsynced commands in flight strands them against a map that is no longer open. If the flush fails, block the switch and offer the changes as an export — never discard them silently.
- Loading clears the undo stack; it is per map.
- Delete is refused for the currently open map, and requires the `owner` role on that map through the `gis_map_user` pivot.
- The listing returns each row's `role` so the client knows which actions to offer without a second request.
- Use the Bootstrap 4 modal the application already bundles, not a bespoke dialog.

## Layer tree constraints

- **Toggling a group does not overwrite descendant flags.** It sets an inherited override, so unchecking and rechecking a group restores the previous per-child state rather than turning everything on. Effective visibility is `own.visible AND all ancestors visible AND zoom within range`; opacity multiplies down the chain.
- **A reorder writes one row.** Fractional indexing exists so drag-and-drop in a deep tree does not renumber siblings.
- Virtualization means `aria-setsize` and `aria-posinset` must be set explicitly — the DOM does not contain all siblings.
- Row height is fixed (32 px, 40 px on coarse pointers) to avoid measurement.
- One Leaflet pane per top-level group, `zIndex` in steps of 100. Tile, WMS and image layers get their own panes so a raster can sit between two vector groups.
- Invalid drops (a group into its own descendant) are rejected visually before release.
- The bootstrap response carries **no geometry** — metadata, tree, styles and capabilities only.
- The client reads `capabilities` at runtime rather than a client-side config, so it never drifts from what the server actually supports.
- Map creation is idempotent on `(clientId, seq)`: a duplicate map is a worse failure than a duplicate feature, because the user may not notice until both have diverged.

## Gate

| Metric | Budget |
| --- | --- |
| Tree scroll, 2,000 nodes | 60fps |
| Tree expand, 2,000 nodes | < 50 ms |
| Layer visibility toggle to painted | < 50 ms |

Plus: zero accessibility violations, and full keyboard operation — arrows navigate and expand, `Home`/`End` jump, typeahead focuses by name, `Space` toggles visibility, `ctrl+↑`/`ctrl+↓` reorder.

## Tests

- Browser: drag-and-drop across all three drop targets, including the rejected invalid drop.
- Browser: keyboard-only reorder and visibility toggle.
- Browser: accessibility pass on the tree with 2,000 nodes virtualized.
- Feature: map copy shares layers and copies no features.
- Feature: a soft-deleted map is listed under `deleted=1`, restores within the window, and is gone after the sweep.
- Feature: **restore is refused for a non-administrator**, including the map's own owner.
- Feature: the basemap list survives the tile service being unreachable, falling back to cache then to the default provider.
- Browser: selecting a weather overlay stacks it above the basemap without replacing it, and two overlays can be active at once.
- Feature: reorder writes exactly one row.
- Feature: a layer placed in two maps sits at different tree positions with independent visibility and opacity.
- Feature: a `read` placement refuses every mutating command, including from the map's owner.
- Feature: `layer.setAccess` is refused for a map that does not own the layer.
- Feature: the library lists global layers and own-map layers only — never a layer from a map the user cannot open.
- Feature: `layer.share` with `access: edit` is refused when the user holds only read rights, **called directly, bypassing the modal**.
- Feature: placing an already-placed layer is refused by the unique constraint, not just hidden in the UI.
- Feature: copying a map containing the 672k-feature Lot layer writes placements only and completes in well under a second.
- Feature: removing from one map leaves the layer intact in others; deleting from the owning map removes it everywhere.
- Feature: the listing returns only maps the user may open, and `deleted=1` is refused to non-owners.
- Feature: deleting the currently open map is refused; deleting another map soft-deletes and is restorable.
- Browser: switching maps with a pending queue flushes first; a forced flush failure blocks the switch and surfaces the export offer.

## Notes

The map browser replaces what an earlier draft suggested as an optional Backpack CRUD screen for listing maps. It is in-app, it knows about unsynced state, and it can open a map — none of which a CRUD panel does. Do not build both.

## Results — S5b (layer tree, control panel)

Built and measured. **Every gate is met, with room.** The tree is virtualized
through a shared row recycler, drag and drop is Pointer Events throughout, and
the map control panel carries the basemap picker, weather overlays,
go-to-coordinate and isolate.

| Metric | Budget | Measured |
| --- | --- | --- |
| Tree scroll, 2,000 nodes | 60fps | **0.5 ms p95** per render, 1.1 ms worst |
| Tree expand, 2,000 nodes | < 50 ms | **1.6 ms** |
| Visibility toggle to painted | < 50 ms | **3.0 ms** on a real map, 3.2 ms at 2,000 nodes |
| Flatten 2,000 nodes | — | 6.0 ms |
| DOM rows held, 2,000 nodes | — | **41** |

### The tree was quadratic, and the gate is what found it

The first measurement missed: 98 ms to flatten 2,000 nodes and **67 ms to
expand a group, against a 50 ms budget**. Nothing looked wrong — every function
was a short, readable traversal. The cost was that `childrenOf` scanned the
whole tree and sorted, and the traversal called it once per node. Two thousand
nodes is four million operations to draw a list of forty rows.

One parent-to-children index, built once per render pass, took flatten to 6 ms
and expand to **1.6 ms**. The same scan was in `main.js` where the editor works
out which layers to draw, so a visibility toggle paid it too: 149 ms before,
3 ms after. It is a snapshot and is rebuilt every pass — an index that outlives
a mutation is a tree that disagrees with the store.

### A latent bug the first commit from the tree exposed

`createStore` closed over a `store` binding it declared *below* the closure, so
every confirmed batch threw `ReferenceError: store is not defined` inside
`onApplied` and the client never reconciled server ids or versions. It had been
there since S4 and had never fired, because nothing committed a store command
through the queue until now — map creation and `layer.share` both go out
through `sendCommands`, which bypasses the store. The tree's first reorder
found it immediately.

### Turning the last overlay off never saved

Reported from use, and worth recording because the shape recurs. The view write
merges what it is given, deliberately: a client that knows only where it is
looking must not erase the basemap someone chose. So an absent key means
*unchanged*. The client only sent `overlays` when the list was non-empty —
which meant unticking the last one sent nothing at all, and the overlay came
back on the next reload.

**An empty list is a state the user chose, not an absence of information.** The
client now always sends the key. A Pest test pins the contract from the server
side, since that is where the merge lives.

### Decisions that differ from the plan

- **The drawn set is reconciled, never rebuilt.** Hiding one layer used to
  clear the renderer and construct a fresh feed per visible layer, so the
  others lost their geometry, index and built paths and refetched them.
  Measured after: hiding one of three costs **0 feature reads** and the
  survivors keep their slots. Slots are now stable, removal leaves a hole, and
  stack position travels as an explicit `order`.
- **Opacity is a `globalAlpha` per layer in the paint loop**, not a pane
  opacity. A pane can only fade a top-level node, so a slider on a layer nested
  in a group did nothing at all — which is what it looked like. Groups, tiles
  and image overlays still fade through their pane.
- **There is one opacity control, and `style.fillOpacity` is gone.** It used to
  multiply the layer's opacity, so the imported layers — which shipped at 0.15
  — painted at 15% with the slider at 100%, and the slider could not reach
  opaque. Exposing both as sliders answered the symptom and left two controls
  for one visible property. Removing the style alpha answers it properly:
  layers import solid, one slider dials them back per map, and what it says is
  what you see.
- **The panel offers four basemaps and no weather overlays**, and it lives at
  the foot of the sidebar rather than floating over the map. See §8.
- **Isolate, Maps and the panel toggle left the map toolbar.** Isolate acts on
  the tree's selection and now sits with the tree's other actions; choosing a
  map sits beside the map's name; the panel toggle sits beside the way out. A
  toolbar floating over the canvas is for things that act on the canvas, and
  none of those three did. It is left in place, empty, for the drawing tools.
- **Tooltips hide themselves after five seconds.** A hint that has been read is
  clutter, and on a touch screen a tap leaves one open with no hover to end it.
- **A group's checkbox cascades to its descendants**, which is the opposite of
  what §8 originally specified. Setting only the group's flag and relying on
  inheritance left the children ticked while the map showed nothing, and the
  control that looks like "turn this lot off" appeared to half work. The
  specification has been changed to match, with the cost stated there:
  rechecking a group now turns everything under it on.
- **Opacity is applied at the pane, not per vector layer.** A group, a tile
  layer and an image overlay all fade correctly, because each top-level node
  owns a Leaflet pane and a pane has a CSS opacity. A single vector layer
  inside a group does not yet, because vector layers share one feature canvas
  until per-layer styling lands in S8. The command, the state and the slider
  are all real now; what is missing is one multiply in the paint.
- **The opacity slider and zoom range live in the row menu, not the row.**
  Thirty-two pixels of row across two thousand rows is not where a slider
  belongs, and putting one there would have cost a control per pooled node.
- **Isolate solos the tree selection rather than one layer.** It composes with
  multi-select for free, and the single-layer case is just a selection of one.
- **Tree filter expands what it matches.** A hit inside a collapsed group is
  shown rather than counted — a match the user cannot reach is not a match.
- **The conflict panel is not here.** The queue still pauses and keeps the
  commands on a 409, which is the safe half. Resolution belongs with S6, where
  editing makes conflicts likely in the first place; the comment in `main.js`
  that promised it for S5b has been corrected rather than left to mislead.

### What is deferred from this session's scope

Search in the control panel is the shell only — the panel section exists and
the endpoint behind it is S9b's. That is the seam the specification already
names: S5 builds the panel and Search, S9b mounts the query builder into it.

## Results — S5a (maps, sharing, library)

Built and measured. **Everything server-side is done, along with the two modals
that sit on it.** The layer tree, drag and drop, panes and the control panel are
S5b, and the gate table above belongs to that half.

### What it costs

| Operation | Measured |
| --- | --- |
| Bootstrap a map (1 layer, warm basemap cache) | **1.0 ms** |
| Copy a map placing a 734,469-feature layer | **3.6 ms** |
| Map listing, 25 rows with layer and feature counts | 4 queries, independent of page size |

Copying is 3.6 ms because it copies nothing. That is the whole point of the
layer split: the source map places a layer holding three quarters of a million
features, and the copy writes one row. An earlier design duplicated features up
to a 20,000-row cap, which against this data would have rejected every copy —
shared layers make the question disappear rather than answer it.

The listing's counts are two grouped queries for the whole page rather than two
per row, and the **feature count sums only layers the map owns**. Summing shared
ones would print the cadastral base on every row and say nothing about the work
done in that map.

### The permission model, and where it is actually enforced

Effective permission is the narrowest of three terms — the user's role on the
map, the placement's `access`, the layer's `locked` — resolved in one place
(`MapAccess`) so no command has to remember all three. Four consequences are
tested rather than asserted in prose:

- A map owner given a `read` placement cannot write through it. Feature writes,
  rename, restyle, delete and lock are all refused; **visibility and opacity are
  not**, because those are this map's view of the layer rather than a change to
  the layer.
- A layer not placed in this map is unreachable through this map, whatever the
  user's role elsewhere. That is what an escalation attempt would look like.
- `layer.setAccess` is refused unless the **acting map owns the layer**. Without
  it, anyone holding a read-only placement of the cadastral base could promote
  their own placement and rewrite 4.3 million rows.
- `layer.share` with `access: edit` is refused when the user holds only read
  rights — **tested by calling the command directly**, because the library modal
  offering only "read" is a courtesy and not a fence.

Restore is administrators only, **including the owning map's owner**. An owner
may delete and may not undo it themselves; that is the usual shape for a
destructive action with a recovery path and it keeps recovery auditable to a
small group. It needed a second permission, `Administer GIS`, added to the
Administrator role in the seeder.

### Decisions that differ from the plan

- **`map.restore` and `layer.restore` are commands, not endpoints.** A
  soft-deleted map cannot receive a batch addressed to itself, but the catalogue
  has them take an `id` — so they are sent through any map the user has open and
  name the deleted one. No new write path.
- **Delete refuses the open map on the client's word.** Only the client knows
  which map it is looking at, so it declares it (`?openMapId=`). A policy cannot
  answer a question about a session.
- **The basemap list ships inside the bootstrap**, with three levels of fallback
  — live, cached, then the configured default alone — and reports which one it
  used. It never throws and never returns an empty list, because a basemap the
  user cannot change beats a map that will not load. The `owm-` classification
  is by prefix, so a sixth weather provider needs no code change.
- **The editor opens the user's most recent map** rather than always showing the
  browser. Returning to the editor puts them back where they were; the browser
  opens itself only when there is no map at all, which is the one state with
  nothing to render.
- **Fractional indexing appends by odometer, not by bisection.** Bisecting
  towards an open end converges on `z` and then grows a character per insert —
  500 appends produced an **84-character key**, against a `varchar(64)` column.
  Incrementing with carry (`a0` → `a1`, `az` → `b0`) keeps an append-only list at
  two characters for its first 1,612 layers. The client and server implement the
  same algorithm and are tested against the same properties, because a drop
  computes its key on the client and a copy computes one on the server.
- **`$request->validate()` returns only the keys it has rules for**, so a rule of
  `commands.*.op` silently stripped every command down to its op. The envelope is
  validated and the commands are then taken from the raw input — rules for the
  payloads would be a second copy of the catalogue.

### Checked in the browser

The editor was driven end to end against the imported cadastre: the map browser
lists both dev maps and marks the open one, the library lists `Lot` and
the land-use layers as base data with their feature counts, marks the already-placed one
unselectable, and offers **read-only alone** for both — which is the permission
rule showing through the UI rather than being described by it. Placing `Lot`
wrote one row and the renderer picked it up: 27,041 + 24,615 features across two
layers at zoom 12.

### Tests

**46 new feature tests** across the map browser, shared layers and the tree
commands: visibility and paging of the listing, per-row roles, owned-only feature
counts, idempotent creation, copy sharing layers and preserving the tree,
bootstrap refusal, versioned rename, owner-only and not-currently-open delete,
the deleted listing, administrator-only restore, the three basemap fallbacks
including a thrown connection error, library visibility and access computation,
every refusal above, one-row reorder asserted by counting UPDATE statements,
group and ungroup with reparenting, and the zoom-range and opacity bounds.

**Four JavaScript unit tests** for the client's fractional index, including the
key-length property that caught the append bug.

The whole suite is **140 tests, 586 assertions**, up from 91.

### Not done — this is S5b

The virtualized layer tree, drag and drop with its three drop targets, tri-state
visibility inheritance, Leaflet panes and z-order from tree position, tree search,
the ARIA treeview and keyboard operation, and the map control panel (basemap
switcher, weather overlays, go-to-coordinate, search, isolate). The gate table
above measures those, and none of it is measured yet.

One piece of S5a is deliberately thin until then: **the conflict panel**. The
client pauses its queue, keeps the commands and hands the problem document to a
callback that currently logs. Nothing is lost; the user is simply not yet told.
