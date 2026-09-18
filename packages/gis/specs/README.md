# GIS build sessions

One file per session. Each is an execution plan: what to build, what not to, and the gate that says it is done.

**These files do not replace the specification.** [`GIS Web Interface — Technical Specification.md`](GIS%20Web%20Interface%20—%20Technical%20Specification.md) remains the single source of truth for architecture — principles, data model, API contract, performance budgets, security and failure modes. Session files point into it by section number and own only the scoping and sequencing. When the two disagree, the specification wins and the session file is wrong.

## Before starting any session

1. Read `.ai/rules/gis.md`. It holds the settled decisions — Vite, jQuery, vendored Leaflet, `/api/geo`, `gis_` prefix, `brick/geo`, Pest only, and **no `backpack:crud` in this package**. Do not re-litigate them.
2. Read the session file, then the specification sections it names.
3. Check the session's dependencies are actually delivered, not merely started.

## Order

v1 ships after S11. Sessions are sequential unless the file says otherwise.

| # | Session | Depends on |
| --- | --- | --- |
| [S0](S00-package-skeleton.md) | Package skeleton | — |
| [S1](S01-data-model.md) | Data model | S0 |
| [S1b](S01b-import-bencana.md) | Import from the bencana database | S1 |
| [S2](S02-renderer.md) | Rendering engine — **hard gate** | S1b |
| [S3](S03-feature-read-api.md) | Feature read API | S1b, S2 |
| [S4](S04-store-commands-sync.md) | Store, commands, undo, sync | S1 |
| [S5](S05-map-layer-tree.md) | Map browser, layer tree, library, control panel | S4, S2 |
| [S5b](S05b-image-overlays.md) | Image overlays | S5 |
| [S6](S06-drawing-editing.md) | Drawing and vertex editing | S4, S2 |
| [S7](S07-geometry-operations.md) | Geometry operations | S6 |
| [S8](S08-styling-labels-legend.md) | Styling, labels, legend | S5, S2 |
| [S9](S09-attributes-selection-query.md) | Attribute table and selection | S5, S8 |
| [S9b](S09b-query.md) | Spatial and attribute query | S9 |
| [S10](S10-measurement-units.md) | Measurement and units | S6 |
| [S11](S11-responsive-a11y-performance.md) | Responsive, a11y, performance | all v1 |
| — | **v1 ships** | |
| [S12](S12-import-crs.md) | Job protocol, import, CRS, external sources | S11 |
| [S14](S14-export.md) | Export and print layout | S11 |
| [S15](S15-share-links.md) | Share links and embed | S14 |

S5, S6, S8 and S9 are each large enough that they may split in two when reached. That is expected; the gate is what matters, not the session count.

## Definition of done

A session is complete when its functionality works, its budgets are met on the reference device and recorded against the previous baseline, its tests pass under `php artisan test`, its failure modes from specification section 21 behave as specified, and its UI passes an accessibility check.

Record the measured budget numbers in the session file under **Results** when you finish. That is what makes the next session's comparison meaningful.
