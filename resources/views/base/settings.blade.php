@extends('base.blank')

@php
    $defaultBreadcrumbs = [
        trans('backpack::crud.admin') => url(config('backpack.base.route_prefix'), 'dashboard'),
        __('Settings') => false,
    ];

    // if breadcrumbs aren't defined in the CrudController, use the default breadcrumbs
    $breadcrumbs = $breadcrumbs ?? $defaultBreadcrumbs;
@endphp

@section('header')
    <div class="container-fluid">
        <h2>
            <span class="text-capitalize">{{__('Settings')}}</span>
            <small id="datatable_info_stack">{{__('Administrator Settings')}}</small>
        </h2>
    </div>
@endsection

@section('content')
    <div class="row mt-4">
        {!! $settings->render() !!}
    </div>
@endsection
