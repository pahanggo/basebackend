<?php

use Illuminate\Support\Facades\Route;
use Workflow\Http\Controllers\WorkflowDesignerController;

/*
|--------------------------------------------------------------------------
| Workflow package routes
|--------------------------------------------------------------------------
|
| Registered automatically by WorkflowServiceProvider — a downstream app
| gets a working /admin/workflows/* section with zero app/ files needed.
|
*/

Route::group([
    'prefix' => config('backpack.base.route_prefix', 'admin').'/workflows',
    'middleware' => array_merge(
        (array) config('backpack.base.web_middleware', 'web'),
        (array) config('backpack.base.middleware_key', 'admin')
    ),
    'namespace' => 'Workflow\Http\Controllers',
], function () {
    Route::crud('definitions', 'WorkflowDefinitionCrudController');

    Route::get('definitions/{workflowDefinition}/design', [WorkflowDesignerController::class, 'edit'])
        ->name('workflow.designer.edit');
    Route::post('definitions/{workflowDefinition}/design', [WorkflowDesignerController::class, 'update'])
        ->name('workflow.designer.update');
});
