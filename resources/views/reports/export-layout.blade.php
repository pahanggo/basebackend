@if (trim($__env->yieldContent('report-header')))
    @yield('report-header')
@else
    <h3>
        {{$reportGroup}} :: {{$reportTitle}}
    </h3>
    @if($reportSubtitle)
    <p>
        {{$reportSubtitle}}
    </p>
    @endif
@endif

<p>
    Generated At: {{format_datetime(now())}}
</p>

<p></p>

@yield('report-body')