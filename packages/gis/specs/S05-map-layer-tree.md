# S5 — Map CRUD and layer tree

**Depends on:** S4, S2
**Specification:** §7 (map creation, listing, bootstrap), §8 (whole section), §18 (layer tree)
**Gate:** tree budgets met, accessibility clean.

## Goal

The map browser that chooses which map is open, and the tree that owns layer identity, order, visibility, opacity and z-order.

May split in two: the map half (browser modal, create/copy/delete, bootstrap) and the layer half (tree, drag-and-drop).

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
- `availableAccess` is computed per user per layer. `read` for anything listed; `edit` **only** where they already hold edit rights on that layer. Without this a user could add the cadastral base as editable to their own map and rewrite 1.4M features they were only meant to read.
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

- The **Features** column counts only layers this map owns. Summing shared layers would print the 1.4M cadastral base on every row and tell the user nothing.
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

## Results

_Fill in when complete._
