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

## Results — the performance overlay and a first pass (S11a)

**Partly done.** The instrumentation is built and the responsive and
accessibility rules are in. The mobile EDITING adaptations — the ones that need
new gestures rather than new layout — are not.

**The overlay ships in production behind `Ctrl+Shift+P`**, not only in
development, because the reports that matter come from real deployments with
real data. A budget met on a developer's machine over a fixture is a claim
about the fixture.

It reads two sources because they answer different questions. The renderer's
counters say what the last frame cost — candidates, drawn, vertices, build and
paint. `PerformanceObserver` says what the whole page did, including work this
module knows nothing about; a frame that never ran reports nothing, so the
frame counter alone cannot see a blocked main thread. Long tasks are recorded
whether or not the panel is open, because the point is to catch the ones nobody
was watching for, and each one is logged with what the user was doing — "a
240 ms task" is not actionable and "a 240 ms task while the polygon tool was
active with 40,000 features drawn" is.

The budgets live in one exported table, so the overlay's highlighting and any
later gate cannot disagree about what "over" means. The frame arithmetic is
pure and has its own tests: a rate computed over the wrong span reads as a
perfectly plausible number, and the whole point of the panel is that its
numbers can be trusted.

**Measuring it in this session's browser was not possible**, and the reason is
worth recording: the automated tab runs backgrounded, `document.hidden` is
true, and Chrome throttles `requestAnimationFrame` to nothing — measured at
zero callbacks in 1.5 seconds. The overlay renders correctly and every counter
reads zero, which looks exactly like a broken panel. Anyone measuring here must
use a foreground window.

### Responsive and accessibility

- Below 720 px the sidebar becomes an overlay drawer rather than a column, the
  attribute table a 62% sheet rather than a 40% dock, and the legend starts
  smaller — fourteen land-use categories is most of a phone screen.
- Coarse pointers get 44 px targets on every control that had a mouse-sized
  one, rather than only where it was remembered.
- `:focus-visible` is restyled and never removed. A focus ring that is
  invisible is a keyboard user locked out.
- `prefers-reduced-motion` drops the transitions; they are decoration, and the
  information is in the position rather than the travel.
- `prefers-contrast: more` gives the readout and the legend a solid backing,
  because both sit over a map whose colours are not ours.

### Still to build in S11

- **Mobile editing**: the magnifier, crosshair-and-confirm placement,
  long-press to edit, and thumb-reachable undo. These are new gestures rather
  than new layout, and they want a real device rather than a narrowed window.
- **The attribute table as a card list** on a phone. It is a sheet at a
  sensible height now, but still a table.
- **The command palette** and the full keyboard-shortcut pass.
- **A measured accessibility audit.** The rules above are the obvious pass;
  nothing has yet been run through an auditing tool or a screen reader, and
  saying otherwise would be claiming a check that was not made.
- **The degradation thresholds** from §19: automatic clustering above 20,000
  drawn, the warning banner above 40,000, stepping the area threshold up after
  two seconds over 33 ms. The overlay now flags all three, which is the
  measurement half; acting on them is the other.
