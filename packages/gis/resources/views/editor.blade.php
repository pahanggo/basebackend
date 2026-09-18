@extends(backpack_view('layouts.plain'))

{{--
    The map is the application at this URL. The plain layout gives us the head
    and the shared scripts without the admin sidebar, header or breadcrumbs;
    these two sections drop its centred container so the map fills the viewport
    (specification section 17).
--}}
@section('body_class', 'gis-page')
@section('container_class', 'gis-shell')

@section('before_scripts')
    {{-- Leaflet is the vendored UMD build, loaded once as the global `L`.
         Never an import, never a second copy (specification section 3). --}}
    <link rel="stylesheet" href="{{ asset('packages/leaflet/dist/leaflet.css') }}">
    <script src="{{ asset('packages/leaflet/dist/leaflet.js') }}"></script>
    <script>L.Icon.Default.imagePath = '{{ asset('packages/leaflet/dist/images') }}/';</script>
@endsection

@section('content')
    <div id="gis-app" class="gis-app">
        <div id="gis-map" class="gis-map"></div>

        <a href="{{ backpack_url('dashboard') }}" class="gis-back" title="{{ __('Back to dashboard') }}">
            <i class="la la-arrow-left"></i>
            <span>{{ __('Back to dashboard') }}</span>
        </a>
    </div>

    <script type="application/json" id="gis-bootstrap">@json($bootstrap)</script>
@endsection

@section('after_scripts')
    @vite(['packages/gis/resources/scss/gis.scss', 'packages/gis/resources/js/main.js'])
@endsection
