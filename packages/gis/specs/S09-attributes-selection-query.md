# S9 — Attributes, selection and query

**Depends on:** S5, S8
**Specification:** §14 (whole section), §6 (generated columns), §7 (schema commands)
**Gate:** sort of 10,000 rows < 200 ms; selection stays consistent across map, table and tree.

## Goal

The attribute table as the map's equal — and its accessible equivalent. May split in two: table and schema, then selection and query.

## In scope

- Virtualized attribute table sharing `virtual-list.js` with the layer tree
- Per-column filters typed from `attr_schema`
- Inline editing with per-type editors and schema validation
- Bulk edit across a selection as one command
- Schema commands: add, rename, retype, delete field; generated-column migration when `indexed`
- One selection set shared across map, table and tree
- Marquee, lasso, by-attribute and by-spatial-relation selection
- Client-side short-circuit for trivially small selections against rbush; the query endpoint itself is S9b
- Feature popups from a per-layer template
- CSV export of the current filtered view is **v2** (§15), not this session

## Out of scope

The query endpoint and its panel (S9b). Import-inferred schema (S12) — the schema machinery built here is what that will populate.

## Deliverables

```
packages/gis/
  resources/js/ui/{attribute-table.js,filter-panel.js,popup.js}
  resources/js/map/select/{marquee.js,lasso.js,query.js}
  src/Commands/Schema*.php
  src/Schema/GeneratedColumnMigrator.php
```

## Constraints that apply here

- **Only viewport rows are in the DOM.** 10,000 rows scroll at 60fps because the recycler from S5 is doing its job.
- Selection highlight draws on the **overlay canvas**, so changing selection never repaints features (S2).
- Selection tools answer from the loaded set only. Anything needing a predicate over the whole layer goes through S9b's endpoint — against 4.3M features the client cannot be the source of truth for "which features match".
- Generated columns are limited to **eight per layer**; beyond that write cost outweighs read benefit.
- Property keys are validated against `^[a-zA-Z_][a-zA-Z0-9_]{0,63}$` before becoming column names (§20).
- **Popup and table values render as text.** `innerHTML` and jQuery's `.html()` are forbidden on any path touching feature data. HTML popup templates are opt-in per layer, restricted to users with edit rights, and still escape every interpolated value: the template author controls structure, never content.
- The attribute table is the accessible equivalent of the map (§18). Everything selectable on the map must be selectable and readable here.

## Gate

| Metric | Budget |
| --- | --- |
| Attribute table sort, 10,000 rows | < 200 ms |
| Table scroll, 10,000 rows | 60fps |
| Selection change repaint | < 8 ms |

## Tests

- Browser: sort, filter and scroll a 10,000-row table within budget.
- Browser: selection stays consistent across map, table and tree in both directions.
- Feature: schema commands, including the generated-column migration and the eight-column limit.
- Feature: an attribute value containing a script tag renders inert in popup, label and table.


## Results

_Fill in when complete._
