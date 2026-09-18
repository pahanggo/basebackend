# S11 — Responsive, accessibility and performance

**Depends on:** every v1 session
**Specification:** §17, §18, §19, §21
**Gate:** AA audit clean, mobile budgets met. **v1 ships.**

## Goal

Make everything built so far work on a phone, with a keyboard, and under failure.

## In scope

- Breakpoint layouts: docks, overlay drawers, bottom sheet with detents
- Container queries so a component renders correctly at 320 px or full width
- Mobile editing: magnifier, crosshair-and-confirm, long-press to edit, thumb-reachable undo/confirm
- Mobile adaptations: attribute table as a card list, tree drag handles, compact measurement bar
- Safe areas and orientation
- Full accessibility pass: ARIA, focus, contrast, motion, announcements, command palette
- Performance overlay behind a flag, `PerformanceObserver` long-task logging
- Every failure mode in §21 behaving as specified
- Degradation strategy thresholds

## Out of scope

Nothing — this is the session that closes v1. If something was deferred from an earlier session, it lands here or it does not ship.

## Deliverables

```
packages/gis/resources/js/
  ui/{bottom-sheet.js,command-palette.js,perf-overlay.js}
  lib/failure.js
packages/gis/resources/scss/    responsive rules
```

## Constraints that apply here

- **Pointer Events exclusively.** No `mousedown`/`touchstart` branching anywhere in the codebase — one code path covers mouse, touch and stylus, and stylus on a tablet is a realistic use for this tool. Detection is `matchMedia('(pointer: coarse)')`, re-evaluated on change, since a tablet with a keyboard case switches modes.
- **Vertex editing under a fingertip is unusable without help.** The magnifier and the crosshair-and-confirm alternative are not polish; without them mobile editing does not work.
- Container queries, not viewport media queries, so a component renders correctly in a 320 px dock and a full-width sheet alike.
- `ResizeObserver` on the map container calls `invalidateSize()`, debounced at 100 ms, so dock resizing does not thrash the renderer.
- **The map canvas cannot be made fully accessible, so an equivalent non-visual path is provided instead**: the attribute table is the accessible equivalent of the map view, plus keyboard pan/zoom/feature-cycling and an on-demand text summary of the current view.
- **"Something went wrong" is not acceptable in any string in this application.** Every error names what failed and what the user can do next.
- One notice per failure class per minute, not one per occurrence.
- **Never lose user work silently.** Unsynced edits are always exportable.

## Gate

- Zero accessibility violations across all chrome; WCAG 2.1 AA.
- Usable at 200% browser zoom without horizontal scrolling.
- All §19 budgets met at 390 x 844 as well as 1280 x 800.
- Every row of the §21 failure table demonstrated.
- Initial load to interactive < 2 s on Fast 3G; initial JS < 300 kB gzipped.

## Tests

- Browser: accessibility pass on every panel, modal and the tree.
- Browser: keyboard-only completion of each of the ten core journeys.
- Browser: the full §19 budget suite at both viewports.
- Browser: failure modes simulated — offline mid-edit, 409, 5xx, missing overlay image, tile server down, worker crash.

## Notes

Before declaring v1 done, revisit §23 open question 7: a drawing-and-measuring tool whose output cannot leave the browser is useful for annotating and inspecting, less so for producing anything. If v1 has real users rather than being a staged build toward v2, that is worth confirming now.

## Results

_Fill in when complete._
