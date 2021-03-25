@extends(backpack_view('blank'))

@php
  $breadcrumbs = [
      trans('backpack::crud.admin') => url(config('backpack.base.route_prefix'), 'dashboard'),
      'Reports' => route('reports.index'),
      $reportName => null,
  ];
@endphp

@section('header')
<section class="content-header">
    <div class="container-fluid mb-3">
    @if (trim($__env->yieldContent('report-header')))
        @yield('report-header')
    @else
        <h3>
            {{$reportGroup}} :: {{$reportTitle}}
        </h3>
        @if($reportSubtitle)
        <small>
            {{$reportSubtitle}}
        </small>
        @endif
    @endif
    <hr>
    </div>
</section>
@endsection

@section('content')
    <form class="card">
        <div class="card-body">
            @if (trim($__env->yieldContent('report-filters')))
                @yield('report-filters')
            @else
                <div class="float-right">
                    <button name="export" value="excel" class="btn btn-default">
                        <i class="la la-download"></i> Export
                    </button>
                </div>
            @endif
        </div>
    </form>
    @yield('report-body')
@endsection