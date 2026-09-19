# S5d — Sublayers

**Depends on:** S5b, S3, S2
**Specification:** §6 (data model), §7 (API contract, GIS1), §10 (styling and symbology), §19 (budgets)
**Gate:** a sublayer toggle costs zero feature reads and paints in under 50 ms; ≤ 64 paint states per layer.

## Goal

Split one layer into sublayers by the value of one of its feature properties. Each distinct value becomes a row under its layer in the tree, with its own checkbox, opacity and fill and line colours.

This is the **categorized** half of S8, pulled forward because a map that paints Perumahan and Hutan the same green is not saying anything, and because the layer that most needs it holds 2.9 million features.

## Already built, and the constraint it imposed

Fill and line colour on a layer shipped in S5b, writing `layer.setStyle`. **That path is unusable for the imported data**, and finding out why is what decided this session's data model: `layer.setStyle` goes through `MapAccess::mayEditLayer`, which refuses a locked layer, and every imported layer is locked. The two layers with anything to classify were the two the specification's design could not reach.

So a classification is **placement** state, beside `visible` and `opacity`, and the specification was amended rather than followed (§6). It is also the better answer on its own terms: the same land use is coloured by category in a planning map and by district in an administrative one.

## In scope

- `gis_map_layer.classification`, and the two commands that write it
- `GET /layers/{layer}/values` — bounded discovery of an attribute's distinct values
- `classify=` on the feature read; GIS1 version 3 carrying a class index and dictionary
- Per-class paint batching in the renderer
- Sublayer rows in the tree, with checkbox, opacity and colours

## Out of scope

Labels, the legend panel, and the graduated, rule-based and heatmap modes — all S8. Nested classification: a layer splits by one field, and seeing `gunatanah_subkategori` means splitting by that field instead.

## Deliverables

```
packages/gis/database/migrations/..._add_classification_to_gis_map_layer.php
packages/gis/src/Support/Classification.php
packages/gis/src/Commands/Handlers/{LayerSetClassification,LayerSetClassState}.php
packages/gis/src/Http/Controllers/Api/LayerValuesController.php
packages/gis/resources/js/map/style/classify.js
packages/gis/resources/js/ui/sublayer-panel.js
```

## Constraints that apply here

- **Filter on the client, never in the read.** There is no index inside `properties` and `ix_layer_read` has no room for one, so a per-class server filter would scan once per visible class and break the `held`/append pan optimisation. It would also buy nothing: the cap never engages, so the client already holds every feature the viewport has.
- **Ship one narrow value per feature, never `properties`.** Measured at zoom 12 over Kuantan on the land-use layer: 15.33 MB plain, 15.49 MB with `classify=`, 41.39 MB with `fields=1`. The class is 1% of payload and nothing measurable in time; the attribute document is 2.7x.
- **Discovery must be bounded, and the bound is `LIMIT` past the cap.** It makes the pathological case the fast one — a unique key stops the scan at the 257th distinct value — while a `GROUP BY` for counts cannot stop early and exhausts memory on the same field.
- **A class's opacity is not a second alpha.** It is one more level of the inheritance chain that already runs group → layer. `style.fillOpacity` remains forbidden.
- **The class index lives inside `geometry`, beside `types`.** `compact()` rebuilds every typed array it knows about and silently drops what it does not, so a parallel array desynchronises on the first pan eviction.
- **Anything `replaceGeometry` does not carry over is reset on every pan that reads.** `evict()` calls it after every eviction.

## Gate

| Metric | Budget | Measured |
| --- | --- | --- |
| Sublayer toggle to painted | < 50 ms | 15–22 ms |
| Feature reads caused by toggling, recolouring or dimming a class | 0 | 0 |
| Commands sent by one opacity drag | 1 | 1, over 41 `input` events |
| Read payload cost of `classify=` | — | +1.0% (15.33 → 15.49 MB) |
| Distinct paint states, `gunatanah_kategori` | ≤ 64 | 14 (13 classes + the leftovers bucket) |

## Tests

- Pest: both commands apply, bump the placement version and not the layer version; a stale version conflicts and reports `classification`; a `read` placement may still classify, a viewer may not; unknown fields, duplicate class values and non-`#rrggbb` colours are refused.
- Pest: the values endpoint lists, sorts, excludes empty values and reports `truncated` rather than listing a unique key.
- Pest: the read emits a class byte and dictionary per feature in both encodings, and neither without `classify=`.
- Node: the accumulator renumbers each frame's dictionary into one, and carries the class index through an eviction; `classify` maps values to slots including nulls, unknown values and PHP's empty-style `[]`; the tree emits sublayer rows in order and refuses drops on them; `replaceGeometry` preserves how a layer paints.

## Results

Measured on the development machine against the real imported data, map `pahang-baru`, layer `Gunatanah Semasa`, split by `gunatanah_kategori` into 13 classes plus the leftovers bucket.

**The import was still running**: 2,237,892 of the layer's eventual 2,863,522 features were present. The read and paint figures scale with what is in the viewport, not with the table, so they stand; the value count may not, and a fourteenth category appearing later would not change any conclusion here.

| Metric | Result |
| --- | --- |
| Sublayer toggle to painted | 15, 17, 18, 19, 22 ms |
| Feature reads from toggling two classes | 0 |
| Commands for a 41-step opacity drag | 1 |
| Commands for two toggles in quick succession | 1 batch, 2 commands |
| Attribute discovery, `gunatanah_kategori` (13 values) | 1.77 s |
| Attribute discovery, `upi` (672,132 values) | 0.00 s, reported as truncated |
| Zoom-12 read, plain / classified / with attributes | 15.33 MB / 15.49 MB / 41.39 MB |

Two bugs worth keeping in the record, because neither raised an error:

- **`replaceGeometry` dropped the classification**, so a classified layer reverted to its base colour on the first pan that read. It is called from `evict()`, which runs after every read that dropped anything.
- **An empty class style arrives from PHP as `[]`, not `{}`**, and `[].fill` is `Array.prototype.fill` — a function, so `entry.style?.fill ?? fallback` never reached the fallback and the swatch painted nothing. Every read of a class style goes through `classStyle()` now.
