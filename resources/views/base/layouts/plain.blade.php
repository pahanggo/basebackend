<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ config('backpack.base.html_direction') }}">
<head>
    @include(backpack_view('inc.head'))
</head>

<body class="@yield('body_class', 'app flex-row align-items-center')">

    @yield('header')

    <div class="@yield('container_class', 'container')">
        @yield('content')
    </div>

    <footer class="app-footer sticky-footer">
        {{-- @include('backpack::inc.footer') --}}
    </footer>

    @yield('before_scripts')
    @stack('before_scripts')

    @include(backpack_view('inc.scripts'))

    @yield('after_scripts')
    @stack('after_scripts')

</body>

</html>
