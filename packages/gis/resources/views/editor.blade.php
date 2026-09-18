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

        {{-- The toolbar the layer tree and the drawing tools mount into (S5b, S6).
             For now it carries the two entry points S5 builds. --}}
        <div class="gis-toolbar btn-group btn-group-sm" role="toolbar">
            <button type="button" id="gis-open-maps" class="btn btn-light">
                <i class="la la-map"></i> {{ __('Maps') }}
            </button>
            <button type="button" id="gis-add-layer" class="btn btn-light">
                <i class="la la-plus"></i> {{ __('Add from library') }}
            </button>
        </div>

        {{-- Shown while a feature read is in flight. Rendered here rather than
             built in JavaScript so the label is translated server-side and the
             client holds no English literal of its own. --}}
        <div id="gis-activity" class="gis-activity" role="status" aria-live="polite" hidden>
            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
            <span>{{ __('Fetching features...') }}</span>
        </div>
    </div>

    <script type="application/json" id="gis-bootstrap">@json($bootstrap)</script>
@endsection

@section('after_scripts')
    @vite(['packages/gis/resources/scss/gis.scss', 'packages/gis/resources/js/main.js'])
@endsection
