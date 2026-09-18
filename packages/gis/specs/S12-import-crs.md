# S12 — Job protocol, import and CRS *(v2)*

**Depends on:** S11
**Specification:** §12 (whole section), §7 (jobs), §5 (queues)
**Gate:** Malaysian grids round-trip within tolerance.

## Goal

Let vector data enter a map by any route other than drawing. This is the largest v2 session and the one the other three depend on.

## Not blocked

The EPSG registry is decided — the table in §12 ships WGS84, Web Mercator, GDM2000 and its Peninsular/East Malaysia RSO projections, Kertau 1968 and its RSO Malaya grid, and UTM 47N/48N in both WGS84 and Kertau. Adding a code is a config entry, not a code change. Revisit only when a real file turns up in an unexpected grid.

## In scope

- The job protocol: `202` + status URL, progress, cancel, failure with stage
- Redis queues and Reverb, neither of which exists in v1
- Import pipeline: sniff, prompt for CRS, stream parse, validate, reproject, simplify, chunked insert, report
- Formats: GeoJSON, KML/KMZ, GPX, zipped Shapefile, CSV/TSV, WKT/WKB, GeoPackage, TopoJSON
- **External read sources**: WMTS, WFS, Esri REST (MapServer, FeatureServer), Cloud-Optimized GeoTIFF — each adding a `kind` enum value
- Local EPSG registry and proj4 reprojection
- Attribute schema inference with confirmation
- Data export of vector formats

## Constraints that apply here

- **The v1 architecture was built so this session changes no shapes.** Import produces `feature.create` commands; it introduces no new write path. If it seems to need one, something has been misunderstood.
- CSV, TSV and spreadsheet input goes through **`maatwebsite/excel`**, already a dependency, whose chunked reader satisfies the streaming requirement. No second CSV path.
- **Files are streamed, never loaded whole.** A 200 MB GeoJSON must not require 200 MB of PHP memory.
- Inserts are chunked at 500–1,000 rows inside a transaction. **Eloquent `create()` in a loop is explicitly forbidden here.**
- **Partial success beats whole-batch failure for imports** — the opposite of commands, which are atomic by design. Rejected features are collected with row number and reason and downloadable as a CSV rejects report.
- Where CRS is absent or unrecognised, prompt with a searchable EPSG list **and a preview showing where the data lands**. An obviously wrong guess is then visible immediately.
- Reprojection accuracy is checked by round-tripping a sample of vertices; drift over 0.5 m raises a warning.
- **External sources are reprojected on ingest, not rendered in their native CRS.** WFS and Esri REST serve features in whatever projection the publisher chose — frequently GDM2000 RSO or a Kertau grid for Malaysian government data, rarely WGS84. Fetch, reproject to 4326 through the registry, store as ordinary features. There is no second coordinate system anywhere in the data model. This is why these sources belong in this session and not in S5.
- **Sanitize on ingest, not at display time** (§20). Strip HTML and control characters from string properties before they reach the database.
- Import is cancellable, and cancellation rolls the job back whole.

## Gate

- Every supported EPSG code round-trips with less than 0.5 m drift, Malaysian grids included.
- A 200 MB file imports without proportional memory growth.
- A file with malformed features imports the good ones and reports the rest.

## Tests

- Feature: round-trip drift per EPSG code in the registry.
- Feature: streaming memory ceiling under a large fixture.
- Feature: partial success — accepted, rejected and the rejects report.
- Feature: cancellation rolls back completely.
- Feature: axis order survives import → store → query → export.

## Results

_Fill in when complete._
