<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Purchase Request demo routes
|--------------------------------------------------------------------------
|
| Registered automatically by PurchaseRequestDemoServiceProvider, gated
| behind config('app.workflow_demo') — same toggle pattern as kitchensink.
|
*/

if (config('app.workflow_demo')) {
    Route::group([
        'prefix' => config('backpack.base.route_prefix', 'admin'),
        'middleware' => array_merge(
            (array) config('backpack.base.web_middleware', 'web'),
            (array) config('backpack.base.middleware_key', 'admin')
        ),
        'namespace' => 'WorkflowDemo\PurchaseRequest\Http\Controllers',
    ], function () {
        Route::crud('purchase-requests', 'PurchaseRequestCrudController');
    });
}
