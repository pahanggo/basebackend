---
paths:
  - resources/views/crud/fields/latlng_picker.blade.php
---

# Fields

## latlng_picker field and latlng_map column
Value shape is Leaflet's {"lat": x, "lng": y} JSON; cast the model attribute to array. The field uses vendored Leaflet (public/packages/leaflet) with tiles from config('services.map_tiles') — the Pahang Go server uses {x}/{y}/{z} order — and Google Places (services.google_places.key) for search, loaded once via bpLoadGoogleMaps(). The latlng_map column renders <img> from the named route static-map (Admin\StaticMapController → App\Services\StaticMapService): Google Static Maps cached 90 days on the local disk under static-maps/, with a tile-stitched GD fallback cached 1 day when Google refuses; responses carry ETag + private max-age and answer 304. Options: field default/zoom/height/search, column width/height/zoom.
