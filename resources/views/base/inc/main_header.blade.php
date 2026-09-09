<header class="{{ config('backpack.base.header_class') }}">
    {{-- On mobile the sidebar is off-canvas, so the brand and the opener live in the header.
         On desktop both move into the sidebar itself (see inc.sidebar) so they collapse with it. --}}
    <button class="navbar-toggler sidebar-toggler d-lg-none ml-3" type="button" data-toggle="sidebar-show" aria-label="{{ trans('backpack::base.toggle_navigation')}}">
        <span class="navbar-toggler-icon"></span>
    </button>
    <a class="navbar-brand d-lg-none" href="{{ url(config('backpack.base.home_link')) }}" title="{{ config('backpack.base.project_name') }}">
        {!! config('backpack.base.project_logo') !!}
    </a>

    @include(backpack_view('inc.menu'))
</header>
