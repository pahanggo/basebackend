<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Workflow package routes
|--------------------------------------------------------------------------
|
| Registered automatically by WorkflowServiceProvider. The canvas/inspector
| editor, definition/instance Backpack controllers, the "My Tasks" widget
| endpoint, and the signed inbound webhook route are added here as they are
| built — this file is intentionally a placeholder for now.
|
*/

Route::group([
    'prefix' => config('backpack.base.route_prefix', 'admin').'/workflows',
    'middleware' => array_merge(
        (array) config('backpack.base.web_middleware', 'web'),
        (array) config('backpack.base.middleware_key', 'admin')
    ),
    'namespace' => 'Workflow\Http\Controllers',
    'as' => 'workflow.',
], function () {
    //
});
