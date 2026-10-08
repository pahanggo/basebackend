@if (trim($__env->yieldContent('report-header')))
    @yield('report-header')
@else
    <h3>
        {{ __($reportGroup) }} :: {{ __($reportTitle) }}
    </h3>
    @if($reportSubtitle)
    <p>
        {{$reportSubtitle}}
    </p>
    @endif
@endif

<p>
    {{ __('Generated At') }}: {{format_datetime(now())}}
</p>

<p></p>

@yield('report-body')