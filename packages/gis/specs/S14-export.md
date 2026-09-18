# S14 — Export and print layout *(v2)*

**Depends on:** S11
**Specification:** §15 (whole section), §13 (CORS)
**Gate:** A3 at 300 DPI within 20 s at p95.

## Goal

Get a map out of the browser — as a screenshot, and as something printable and filed.

Independent of S12: export depends only on the renderer separation already built in S2.

## Not blocked

`tiles.pahanggo.com` returns `access-control-allow-origin: *` — verified 2026-09-18 against a live 200 tile response. The client-side export path is therefore available for the default basemap, provided every tile layer sets `crossOrigin: 'anonymous'`. It remains unavailable for any user-added third-party source that does not send the header, which is the documented fallback to server-side rendering, not a failure.

## In scope

- Client-side export: screen-resolution PNG at 1x/2x, current viewport
- Server-side render: `spatie/browsershot` against a pool of persistent Chrome instances
- The `?render=1` mode of the client application
- Export options: extent, format, page size, orientation, DPI, scale
- Print layout elements and saved layout templates
- World files for PNG/JPEG; geospatial reference in PDF

## Constraints that apply here

- **Three problems make client-only export inadequate**, which is why the server path is the default rather than a fallback: canvas tainting from cross-origin tiles, 300 DPI output (A3 landscape is ~4960 x 3508 px, beyond canvas limits on some mobile browsers), and cross-client consistency of fonts and label placement for a document that will be printed and filed.
- **Image overlays do not composite for free.** The browser applies their `matrix3d` at paint time on a DOM element; `drawImage` does not, and canvas 2D has no perspective transform. The client-side path must warp each overlay itself by subdividing the quad into triangles with per-triangle affine transforms. The server path has no such problem — headless Chrome paints the same DOM the user sees.
- **Chrome runs from a warm pool, not a cold start per job.** Cold-starting per export adds 2–4 s and is the main cause of render timeouts.
- Device scale factor is set to reach target DPI rather than scaling the image afterwards, so text and symbols are genuinely sharp.
- The render URL requires a **single-use signed token scoped to one map and one extent, valid for 60 seconds**, and Chrome runs sandboxed with no network access except the application origin and the tile path (§20).
- Render mode disables label collision randomisation — deterministic placement, and a `window.__renderComplete` promise the capture waits on.
- Client-side export falls back to the server automatically when tainting is detected.
- Export defaults to server-side on mobile regardless of source, given canvas size limits.

## Gate

| Metric | Budget |
| --- | --- |
| Server-side export, A3 at 300 DPI, p95 | < 20 s |

Plus: a rendered map is visually identical to what the user sees, image overlays included.

## Tests

- Feature: render job lifecycle — queued, running, done, failed with stage, cancelled.
- Browser: client-side export composites features, overlays, tiles and image overlays correctly.
- Browser: screenshot comparison between client-side and server-side output of the same view.
- Feature: expired or reused render token is refused.

## Results

_Fill in when complete._
