{{-- latlng_picker field: Leaflet map with a draggable pin and Google Places search.
     Stores {"lat": 3.8, "lng": 103.3} as JSON in a hidden input (Leaflet's LatLng shape).

     Options:
       - default  => ['lat' => 3.8077, 'lng' => 103.326]  starting point when the value is empty (Kuantan)
       - zoom     => 14
       - height   => '300px'
       - search   => true                                   show the Google Places search box
       - api_key  => config('services.google_places.key')
       - tiles / attribution                                default config('services.map_tiles')
--}}
@php
    $default = array_merge(['lat' => 3.8077, 'lng' => 103.326], (array) ($field['default'] ?? []));
    $value = old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? null;

    if (is_string($value) && $value !== '') {
        $value = json_decode($value, true);
    }
    $value = is_object($value) ? (array) $value : $value;
    if (! is_array($value) || ! isset($value['lat'], $value['lng']) || ! is_numeric($value['lat']) || ! is_numeric($value['lng'])) {
        $value = null;
    }

    $field['wrapper'] = $field['wrapper'] ?? $field['wrapperAttributes'] ?? [];
    $field['wrapper']['data-field-type'] = 'latlng_picker';
    $field['wrapper']['data-field-name'] = $field['name'];

    $search = $field['search'] ?? true;
    $apiKey = $field['api_key'] ?? config('services.google_places.key');
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')

    <input type="hidden"
           name="{{ $field['name'] }}"
           value="{{ $value ? json_encode(['lat' => (float) $value['lat'], 'lng' => (float) $value['lng']]) : '' }}"
           data-init-function="bpFieldInitLatlngPickerElement"
           data-default="{{ json_encode(['lat' => (float) $default['lat'], 'lng' => (float) $default['lng']]) }}"
           data-zoom="{{ (int) ($field['zoom'] ?? 14) }}"
           data-tiles="{{ $field['tiles'] ?? config('services.map_tiles.url') }}"
           data-attribution="{{ $field['attribution'] ?? config('services.map_tiles.attribution') }}"
           data-search="{{ $search && $apiKey ? '1' : '0' }}"
           data-api-key="{{ $search ? $apiKey : '' }}">

    @if ($search && $apiKey)
        <input type="text" class="form-control mb-2 latlng-search" autocomplete="off"
               placeholder="{{ trans('backpack::crud.latlng_search_placeholder') }}">
    @endif

    <div class="latlng-map" style="height: {{ $field['height'] ?? '300px' }}; border-radius: 3px; border: 1px solid rgba(0,40,100,.12);"></div>
    <small class="form-text text-muted latlng-coords"></small>

    {{-- HINT --}}
    @if (isset($field['hint']))
        <p class="help-block">{!! $field['hint'] !!}</p>
    @endif
@include('crud::fields.inc.wrapper_end')

{{-- ########################################## --}}
{{-- Extra CSS and JS for this particular field --}}
@if ($crud->fieldTypeNotLoaded($field))
    @php
        $crud->markFieldTypeAsLoaded($field);
    @endphp

    @push('crud_fields_styles')
        <link rel="stylesheet" href="{{ asset('packages/leaflet/dist/leaflet.css') }}">
        <style>
            .latlng-map.leaflet-container { z-index: 0; }
            .pac-container { z-index: 1051; }
        </style>
    @endpush

    @push('crud_fields_scripts')
        <script src="{{ asset('packages/leaflet/dist/leaflet.js') }}"></script>
        <script>
            L.Icon.Default.imagePath = '{{ asset('packages/leaflet/dist/images') }}/';

            // Load the Google Maps Places library once, then run every queued callback.
            window.bpGoogleMapsQueue = window.bpGoogleMapsQueue || [];
            window.bpGoogleMapsLoaded = function () {
                var queue = window.bpGoogleMapsQueue.splice(0);
                queue.forEach(function (callback) { callback(); });
            };
            function bpLoadGoogleMaps(apiKey, callback) {
                if (window.google && google.maps && google.maps.places) {
                    return callback();
                }
                window.bpGoogleMapsQueue.push(callback);
                if (! document.querySelector('script[data-bp-google-maps]')) {
                    var script = document.createElement('script');
                    script.src = 'https://maps.googleapis.com/maps/api/js?v=3&key=' + encodeURIComponent(apiKey) + '&libraries=places&callback=bpGoogleMapsLoaded';
                    script.async = true;
                    script.defer = true;
                    script.setAttribute('data-bp-google-maps', '1');
                    document.head.appendChild(script);
                }
            }

            function bpFieldInitLatlngPickerElement(element) {
                var $wrapper = element.closest('[data-field-type=latlng_picker]');
                var $mapEl = $wrapper.find('.latlng-map');
                var $coords = $wrapper.find('.latlng-coords');
                var $search = $wrapper.find('.latlng-search');
                var fallback = element.data('default');

                var current = null;
                try { current = JSON.parse(element.val()); } catch (e) {}
                var position = (current && isFinite(current.lat) && isFinite(current.lng)) ? current : fallback;

                var map = L.map($mapEl[0]).setView([position.lat, position.lng], parseInt(element.data('zoom'), 10) || 14);
                L.tileLayer(element.data('tiles'), {
                    attribution: element.data('attribution'),
                    maxZoom: 20
                }).addTo(map);

                var marker = L.marker([position.lat, position.lng], { draggable: true }).addTo(map);

                function store(latlng) {
                    var value = { lat: Math.round(latlng.lat * 1e6) / 1e6, lng: Math.round(latlng.lng * 1e6) / 1e6 };
                    element.val(JSON.stringify(value)).trigger('change');
                    $coords.text(value.lat + ', ' + value.lng);
                }

                function moveTo(latlng, zoom) {
                    marker.setLatLng(latlng);
                    map.setView(latlng, zoom || map.getZoom());
                    store(latlng);
                }

                marker.on('dragend', function () { store(marker.getLatLng()); });
                $coords.text(position.lat + ', ' + position.lng);

                // a map drawn inside a hidden tab or modal needs a size refresh once visible
                $(document).on('shown.bs.tab shown.bs.modal', function () { map.invalidateSize(); });
                element.data('leafletMap', map).data('leafletMarker', marker);

                if (element.data('search') == 1 && $search.length) {
                    bpLoadGoogleMaps(element.data('api-key'), function () {
                        var autocomplete = new google.maps.places.Autocomplete($search[0], { fields: ['geometry', 'name'] });
                        autocomplete.addListener('place_changed', function () {
                            var place = autocomplete.getPlace();
                            if (! place.geometry || ! place.geometry.location) {
                                return;
                            }
                            moveTo({ lat: place.geometry.location.lat(), lng: place.geometry.location.lng() }, Math.max(map.getZoom(), 16));
                        });
                    });
                    $search.on('keydown', function (e) {
                        if (e.keyCode === 13) { e.preventDefault(); }
                    });
                }
            }
        </script>
    @endpush
@endif
{{-- End of Extra CSS and JS --}}
{{-- ########################################## --}}
