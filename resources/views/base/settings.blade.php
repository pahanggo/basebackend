@extends('base.blank')

@php
    $defaultBreadcrumbs = [
        trans('backpack::crud.admin') => url(config('backpack.base.route_prefix'), 'dashboard'),
        'Settings' => false,
    ];

    // if breadcrumbs aren't defined in the CrudController, use the default breadcrumbs
    $breadcrumbs = $breadcrumbs ?? $defaultBreadcrumbs;
@endphp

@section('header')
    <div class="container-fluid">
        <h2>
            <span class="text-capitalize">Settings</span>
            <small id="datatable_info_stack">Administrator Settings</small>
        </h2>
    </div>
@endsection

@section('content')
    <div class="row mt-4">
    </div>
{!! $settings->render() !!}
@endsection
