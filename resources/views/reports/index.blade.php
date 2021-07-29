@extends(backpack_view('blank'))

@php
  $breadcrumbs = [
      trans('backpack::crud.admin') => url(config('backpack.base.route_prefix'), 'dashboard'),
      'Reports' => null,
  ];
@endphp

@section('header')
    <section class="content-header">
        <div class="container-fluid mb-3">
            <h1>
                {{__('Reports')}}
            </h1>
            <small>
                {{__('Choose one of the reports below to generate')}}:
            </small>
            <hr>
        </div>
    </section>
@endsection

@section('content')
<div class="row">
    @foreach($reports as $groupName => $reportData)
    <div class="col-sm-4">
        <div class="card">
            <div class="card-header text-value-sm">
                {{$groupName}}
            </div>
            <div class="list-group">
                @foreach($reportData as $report)
                <a class="list-group-item" href="{{route('reports.' . $report['path'] . '.index')}}">
                    {{$report['name']}}
                </a>
                @endforeach
            </div>
        </div>
    </div>
    @endforeach
</div>
@endsection