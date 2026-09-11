<?php

use Illuminate\Support\Facades\Route;
use Workflow\Http\Controllers\WorkflowActorSearchController;
use Workflow\Http\Controllers\WorkflowDesignerController;
use Workflow\Http\Controllers\WorkflowFieldDefinitionValidateController;
use Workflow\Http\Controllers\WorkflowModelCallbackSearchController;
use Workflow\Http\Controllers\WorkflowModelFieldsController;
use Workflow\Http\Controllers\WorkflowModelSearchController;
use Workflow\Http\Controllers\WorkflowTransitionController;

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

    Route::post('transition', WorkflowTransitionController::class)->name('workflow.transition');

    Route::get('models/search', WorkflowModelSearchController::class)->name('workflow.models.search');
    Route::get('actors/search', WorkflowActorSearchController::class)->name('workflow.actors.search');
    Route::get('model-callbacks/search', WorkflowModelCallbackSearchController::class)->name('workflow.model-callbacks.search');
    Route::get('models/fields', WorkflowModelFieldsController::class)->name('workflow.models.fields');
    Route::post('field-policy/validate-definitions', WorkflowFieldDefinitionValidateController::class)->name('workflow.field-policy.validate-definitions');
});
