<?php

use Illuminate\Support\Facades\Route;
use Workflow\Http\Controllers\WorkflowActorSearchController;
use Workflow\Http\Controllers\WorkflowDesignerController;
use Workflow\Http\Controllers\WorkflowFieldDefinitionValidateController;
use Workflow\Http\Controllers\WorkflowInlineUpdateController;
use Workflow\Http\Controllers\WorkflowModelCallbackSearchController;
use Workflow\Http\Controllers\WorkflowModelFieldsController;
use Workflow\Http\Controllers\WorkflowModelSearchController;
use Workflow\Http\Controllers\WorkflowSampleRecordSearchController;
use Workflow\Http\Controllers\WorkflowShowController;
use Workflow\Http\Controllers\WorkflowSimulateController;
use Workflow\Http\Controllers\WorkflowTimelineController;
use Workflow\Http\Controllers\WorkflowTransitionController;
use Workflow\Http\Controllers\WorkflowWebhookController;

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
    Route::get('show', WorkflowShowController::class)->name('workflow.show');
    Route::post('show/update', WorkflowInlineUpdateController::class)->name('workflow.show.update');
    Route::get('timeline', WorkflowTimelineController::class)->name('workflow.timeline');

    Route::get('models/search', WorkflowModelSearchController::class)->name('workflow.models.search');
    Route::get('actors/search', WorkflowActorSearchController::class)->name('workflow.actors.search');
    Route::get('model-callbacks/search', WorkflowModelCallbackSearchController::class)->name('workflow.model-callbacks.search');
    Route::get('models/fields', WorkflowModelFieldsController::class)->name('workflow.models.fields');
    Route::post('field-policy/validate-definitions', WorkflowFieldDefinitionValidateController::class)->name('workflow.field-policy.validate-definitions');

    Route::get('definitions/{workflowDefinition}/sample-records/search', WorkflowSampleRecordSearchController::class)
        ->name('workflow.sample-records.search');
    Route::post('definitions/{workflowDefinition}/simulate', WorkflowSimulateController::class)
        ->name('workflow.designer.simulate');
});

/*
| The inbound webhook is deliberately its OWN route group, outside the admin
| prefix/auth/'web' middleware above — an external system calling it has no
| Backpack session (so no CSRF token to send either; 'web' is what applies
| VerifyCsrfToken, hence not included here) and is verified entirely by
| Laravel's own 'signed' middleware instead. See HasWorkflow::signedWebhookUrl()
| for how the URL itself gets generated, and docs/webhooks.md for the full
| integrator-facing contract.
|
| SubstituteBindings is normally pulled in for free by the 'web'/'api'
| middleware groups — since this route deliberately skips both, it has to be
| listed explicitly, or {workflowInstance} never resolves to a real model at
| all (it's what performs implicit route-model binding) and, worse, the
| controller's OTHER parameters silently resolve against the wrong route
| segments entirely (Illuminate\Routing\ResolvesRouteDependencies falls back
| to positional matching for whichever parameters binding didn't handle).
*/
Route::post('workflows/webhook/{workflowInstance}/{edgeId}', WorkflowWebhookController::class)
    ->middleware(['signed', 'throttle:60,1', \Illuminate\Routing\Middleware\SubstituteBindings::class])
    ->name('workflow.webhook');
