# S5b — Image overlays

**Depends on:** S5
**Specification:** §13 (georeferenced image overlay), §7 (image upload), §20 (upload handling)
**Gate:** an overlay survives upload, placement, corner drag, undo, reload and re-render unchanged; §20 upload rules enforced.

## Goal

Upload a scanned site plan and line it up over the map by dragging its four corners.

## In scope

- `POST /api/geo/images` — the one multipart endpoint in v1
- `kind='image'` layers with `source_config` holding path and corners
- The placement flow: pick → name → upload → place → adjust
- Quarter-viewport initial placement
- Four-corner homography, rendered with CSS `matrix3d`
- Corner handles on the edit canvas, plus numeric coordinate entry
- Server-side re-encode, validation and the §20 limits

## Out of scope

Any other file upload. Vector import stays v2 (S12) — this endpoint accepts images and nothing else.

## Deliverables

```
packages/gis/
  src/Http/Controllers/Api/ImageUploadController.php
  src/Http/Requests/ImageUploadRequest.php
  resources/js/map/overlay/{image-overlay.js,homography.js,corner-handles.js}
```

## Constraints that apply here

- **Upload and layer creation are two steps, not one multipart command.** The command endpoint stays JSON-only, which is what keeps it atomic, idempotent and replayable. This mirrors the application's existing `AjaxUploadController`: the file is stored on pick, and only the returned path is submitted afterwards.
- **The transform is projective, not affine.** Each corner drags independently and moves nothing else. An affine fit constrains the image to a parallelogram, cannot represent a plan scanned at an angle, and surprises the user by moving a corner they did not touch. Four corners define a homography — an 8x8 solve, cheap enough per pointer move.
- **Render with `matrix3d` on an `<img>` in its own Leaflet pane.** The GPU does the warp, a corner drag costs no canvas work, and the element composites through the same z-index mechanism as every other layer. A 2D CSS `matrix` cannot express perspective.
- Initial placement covers a quarter of the viewport **by area**, not by width — quarter-width leaves a tall image overflowing off-screen. Formula in §13.
- Handles draw on the edit canvas, so dragging never repaints features.
- A drag gesture is one undo step, coalesced per §16, exactly like a vertex drag.
- **Re-encode every upload server-side** with `intervention/image`, already a dependency. This strips EXIF — including GPS the uploader may not know is embedded — and discards anything smuggled in an ancillary chunk. A file that sniffs as valid PNG can still carry a payload; decoding to pixels and writing fresh is what makes serving it safe.
- Type is decided by content sniffing. Never the extension, never the client's `Content-Type`.
- Deleting the layer leaves the file for the soft-delete window, so an undo still has its image.

## Gate

- Overlay corner drag frame time < 8 ms including the homography solve.
- An overlay round-trips: upload → place → drag → undo → reload → identical rendering.
- Upload limits enforced: 20 MB, PNG/JPEG by sniffing, 10,000 px longer edge, 20 uploads/hour.
- A non-image with a `.png` extension is rejected before anything is written to disk.

## Tests

- Feature: upload validation across type, dimension and size; rejection names the actual value.
- Feature: EXIF, including GPS, is absent from the stored file.
- Feature: a PNG with an appended payload is re-encoded such that the payload is gone.
- Harness page: the homography solve reproduces known corner→corner mappings.
- Browser: place, drag each corner independently, undo, reload, compare screenshots.

## Notes

This is v1's only multipart write and its only file upload. If a later session wants to upload something else, that is a scope decision to raise, not a convention to follow.

## Results

_Fill in when complete._
