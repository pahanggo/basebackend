---
paths:
  - resources/views/crud/fields/latlng_picker.blade.php
  - 'resources/views/crud/fields/ajax_*upload.blade.php'
  - resources/views/crud/fields/slug.blade.php
  - resources/views/crud/fields/switch.blade.php
  - 'resources/views/crud/fields/{money,phone,identity,dependent_select,tags,date_only,time_range}.blade.php'
---

# Fields

## latlng_picker field and latlng_map column
Value shape is Leaflet's {"lat": x, "lng": y} JSON; cast the model attribute to array. The field uses vendored Leaflet (public/packages/leaflet) with tiles from config('services.map_tiles') — the Pahang Go server uses {x}/{y}/{z} order — and Google Places (services.google_places.key) for search, loaded once via bpLoadGoogleMaps(). The latlng_map column renders <img> from the named route static-map (Admin\StaticMapController → App\Services\StaticMapService): Google Static Maps cached 90 days on the local disk under static-maps/, with a tile-stitched GD fallback cached 1 day when Google refuses; responses carry ETag + private max-age and answer 304. Options: field default/zoom/height/search, column width/height/zoom.

## ajax_upload / ajax_multi_upload fields
Both fields upload immediately to the named route ajax-upload (Admin\AjaxUploadController, validated by AjaxUploadRequest) and submit only the stored path: a string for ajax_upload, a JSON array for ajax_multi_upload (cast the attribute to array). Files land on a whitelisted disk under config('ajax_upload.base_path') plus the field's `path` option; disk/path/max_size come from config/ajax_upload.php and the global RestrictFileUploads middleware still applies (so the extension whitelist there decides what is accepted). Shared markup/script live in crud/fields/inc/ajax_upload_markup|script.blade.php and both types mark each other as loaded. Nothing deletes files on the server when a file is removed from the field.

## slug field follows a target field
`type => 'slug'` with `target => 'title'` fills itself from the target input on input/keyup/change (accent-stripped, lowercase, non-alphanumerics collapsed to `separator`, default "-"). Sync stops once the user types in the slug and resumes when they clear it; on edit forms an existing slug that no longer matches the target is left alone. Inside repeatable it looks up the target within the same group first. Any hand-typed value is re-slugified on blur.

## switch field
CoreUI 2 pill switch storing 0/1 through a hidden input (same contract as the checkbox field, so use it for boolean columns). `color` accepts a theme colour name (rendered as .switch-{color}) or any CSS colour, which uses the .switch-custom class plus the --bg-switch-checked-color variable defined in resources/scss/_custom.scss. `onLabel`/`offLabel` render inside the slider via data-checked/data-unchecked; `size` sm|lg. Note: CSS transitions do not advance in a background browser tab, so computed background colours read by automation lag one state behind — check `checked`/hidden values instead.

## money, phone, identity, dependent_select, tags, date_only, time_range fields
All submit through inputs named exactly the field name and round-trip old(): money (hidden decimal, visible formatted; prefix ''/null = plain number formatting; column `money`), phone (Malaysian-first, stores e164/national/display per `store`; reuse the existing `phone` column), identity (MyKad mask + validation or passport; `types` accepts 'ic'|'mykad'|'passport'; to persist the chosen type declare the field as 'name' => ['identity_number', 'identity_type'] — Backpack strips undeclared inputs on save, so the legacy `type_field` option alone is NOT saved; column `identity` with `mask`/`type_attribute`), dependent_select (plain select reloading from `data_source?parent=` when `depends_on` changes; provide server-side `options` closure for edit pages; a disabled select is never submitted so a hidden twin carries '' when the parent is empty), tags (JSON array in a hidden input, cast to array; column `tags` badges), date_only (bootstrap-datepicker wrapper, hidden Y-m-d), time_range (field: name => [start,end], two native time inputs; column `time_range` must use a scalar 'name' plus 'end_name' because Backpack keys columns by name). Alpine fields use x-ignore + Alpine.initTree from the data-init-function hook; UI strings use __() with Malay in lang/ms_MY.json.
