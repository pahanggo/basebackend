@if (backpack_auth()->check())
<!-- Left side column. contains the sidebar -->
<div class="{{ config('backpack.base.sidebar_class') }}">
    <div class="sidebar-brand d-none d-lg-flex">
        <a class="navbar-brand" href="{{ url(config('backpack.base.home_link')) }}" title="{{ config('backpack.base.project_name') }}">
            {!! config('backpack.base.project_logo') !!}
        </a>
        <button class="sidebar-collapse-toggle" type="button" aria-label="{{ trans('backpack::base.toggle_navigation') }}">
            <i class="la la-bars"></i>
        </button>
    </div>
    <!-- sidebar: style can be found in sidebar.less -->
    <nav class="sidebar-nav overflow-hidden">
        <!-- sidebar menu: : style can be found in sidebar.less -->
        <ul class="nav">
            <!-- <li class="nav-title">{{ trans('backpack::base.administration') }}</li> -->
            <!-- ================================================ -->
            <!-- ==== Recommended place for admin menu items ==== -->
            <!-- ================================================ -->

            @include(backpack_view('inc.sidebar_content'))

            <!-- ======================================= -->
            <!-- <li class="divider"></li> -->
            <!-- <li class="nav-title">Entries</li> -->
        </ul>
    </nav>
    <div class="sidebar-footer text-center">
        <small>
            <a href="{{config('backpack.base.developer_link')}}">{{config('backpack.base.developer_name')}}</a><br>
            {{config('app.name')}} - v{{app_version()}}
        </small>
    </div>
    <!-- /.sidebar -->
</div>
@endif

@push('before_scripts')
<script type="text/javascript">
    let sidebarTransition = value => document.querySelector('.app-body > .sidebar').style.transition = value || '';

    // Recover "collapsed to icons" state before first paint so it does not flicker.
    // The mobile hamburger (sidebar-show) is transient and is deliberately not persisted.
    if (localStorage.getItem('sidebar-minimized') === '1') {
        sidebarTransition("none");
        document.body.classList.add('sidebar-minimized');
        setTimeout(sidebarTransition, 100);
    }
</script>
@endpush

@push('after_scripts')
<script>
    // Collapse the sidebar to icons (desktop) and remember the choice
    document.querySelectorAll('.sidebar-collapse-toggle').forEach(toggler =>
    toggler.addEventListener('click', () =>
    localStorage.setItem('sidebar-minimized', Number(document.body.classList.toggle('sidebar-minimized')))
    )
    );
    // Set active state on menu element
    var full_url = "{{ Request::fullUrl() }}";
    var $navLinks = $(".sidebar-nav li a, .app-header li a");

    // First look for an exact match including the search string
    var $curentPageLink = $navLinks.filter(
    function() { return $(this).attr('href') === full_url; }
    );

    // If not found, look for the link that starts with the url
    if(!$curentPageLink.length > 0){
        $curentPageLink = $navLinks.filter( function() {
            if ($(this).attr('href').startsWith(full_url)) {
                return true;
            }

            if (full_url.startsWith($(this).attr('href'))) {
                return true;
            }

            return false;
        });
    }

    // for the found links that can be considered current, make sure
    // - the parent item is open
    $curentPageLink.parents('li').addClass('open');
    // - the actual element is active
    $curentPageLink.each(function() {
        $(this).addClass('active');
    });
</script>
@endpush
