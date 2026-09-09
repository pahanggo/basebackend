{{-- latlng_map column: static map of a {lat, lng} value, served and cached by StaticMapController --}}
@php
    $value = data_get($entry, $column['name']);

    if (is_string($value)) {
        $value = json_decode($value, true);
    }
    $value = is_object($value) ? (array) $value : $value;

    $width = (int) ($column['width'] ?? 200);
    $height = (int) ($column['height'] ?? 120);
    $zoom = (int) ($column['zoom'] ?? 15);
    $hasPoint = is_array($value) && isset($value['lat'], $value['lng']) && is_numeric($value['lat']) && is_numeric($value['lng']);
@endphp

<span>
    @if ($hasPoint)
        <a href="https://www.google.com/maps?q={{ $value['lat'] }},{{ $value['lng'] }}" target="_blank" title="{{ $value['lat'] }}, {{ $value['lng'] }}">
            <img src="{{ route('static-map', ['lat' => $value['lat'], 'lng' => $value['lng'], 'w' => $width, 'h' => $height, 'z' => $zoom]) }}"
                 alt="{{ $value['lat'] }}, {{ $value['lng'] }}"
                 width="{{ $width }}" height="{{ $height }}" loading="lazy"
                 style="border-radius: 3px; border: 1px solid rgba(0,40,100,.12); display: block;">
        </a>
    @else
        -
    @endif
</span>
