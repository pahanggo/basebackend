<?php

namespace Workflow\Http\Controllers\Operations;

use Illuminate\Support\Facades\Route;
use Workflow\HasWorkflow;
use Workflow\Support\TransitionEngine;

/**
 * `use` this trait on any CrudController whose model `use HasWorkflow`, to
 * get a "workflow bulk transition" operation for free — same shape as
 * Backpack's own BulkDeleteOperation (select rows, hit one endpoint), except
 * the payload also names which edge to fire. Each selected row is checked
 * independently (actor_rule + preconditions, via TransitionEngine — the same
 * checks a single-record transition gets), so a mixed selection of
 * qualifying and non-qualifying rows always returns a clear per-row summary
 * rather than succeeding or failing as a whole.
 */
trait WorkflowBulkTransitionOperation
{
    protected function setupWorkflowBulkTransitionRoutes($segment, $routeName, $controller)
    {
        Route::post($segment.'/workflow-bulk-transition', [
            'as' => $routeName.'.workflowBulkTransition',
            'uses' => $controller.'@workflowBulkTransition',
            'operation' => 'workflowBulkTransition',
        ]);
    }

    protected function setupWorkflowBulkTransitionDefaults()
    {
        $this->crud->allowAccess('workflowBulkTransition');

        $this->crud->operation('workflowBulkTransition', function () {
            $this->crud->loadDefaultOperationSettingsFromConfig();
        });

        $this->crud->operation('list', function () {
            $this->crud->enableBulkActions();
            $this->crud->addButton('bottom', 'workflow_bulk_transition', 'view', 'crud::buttons.workflow_bulk_transition');
        });
    }

    /**
     * Fires one edge against every selected entry that can currently take
     * it. Returns {succeeded: [id, ...], skipped: [{id, reason}, ...]} —
     * never a bare failure for the whole request, since "half the selection
     * didn't qualify" is an expected, normal outcome for a bulk action.
     */
    public function workflowBulkTransition()
    {
        $this->crud->hasAccessOrFail('workflowBulkTransition');

        $validated = request()->validate([
            'entries' => 'required|array',
            'edge_id' => 'required|string',
            'inputs' => 'array',
        ]);

        $engine = app(TransitionEngine::class);
        $actor = backpack_auth()->user();
        $inputs = $validated['inputs'] ?? [];

        $succeeded = [];
        $skipped = [];

        foreach ($validated['entries'] as $id) {
            $entry = $this->crud->model->find($id);

            if (! $entry || ! in_array(HasWorkflow::class, class_uses_recursive($entry))) {
                $skipped[] = ['id' => $id, 'reason' => 'Not a workflow-enabled record.'];

                continue;
            }

            $instance = $entry->workflowInstance();

            if (! $instance) {
                $skipped[] = ['id' => $id, 'reason' => 'No active workflow instance.'];

                continue;
            }

            $edge = $instance->version->edge($validated['edge_id']);
            $token = $edge ? $instance->activeTokens()->where('node_id', $edge['from'])->first() : null;

            if (! $token) {
                $skipped[] = ['id' => $id, 'reason' => 'Transition not available from the record\'s current state.'];

                continue;
            }

            $history = $engine->transition($token, $validated['edge_id'], $inputs, $actor);

            if (! $history) {
                $skipped[] = ['id' => $id, 'reason' => 'Not permitted, or a precondition was not met.'];

                continue;
            }

            $succeeded[] = $id;
        }

        return response()->json(['succeeded' => $succeeded, 'skipped' => $skipped]);
    }
}
