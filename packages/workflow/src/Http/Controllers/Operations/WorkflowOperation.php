<?php

namespace Workflow\Http\Controllers\Operations;

use Illuminate\Support\Facades\Route;
use Workflow\HasWorkflow;
use Workflow\Support\TransitionEngine;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;

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
trait WorkflowOperation
{
    protected function setupWorkflowOperationDefaults()
    {
        // Renders the workflow_show icon in the "Tindakan"/actions column
        // (the 'line' stack), alongside show/edit/delete, rather than inside
        // the 'workflow' column itself — see that button view's own
        // docblock. All transitions are fired from the dedicated
        // "show-workflow" page it links to, never inline in the row — so
        // this is the only button this trait registers; registered here in
        // setup() (always runs, regardless of which operation the current
        // request is for) rather than inside setupListOperation(), since
        // addButton() otherwise only ever registers for whichever operation
        // is current when it runs.
        $this->crud->operation(['list', 'show'], function () {
            CRUD::addButton('line', 'workflow_show', 'view', 'workflow::buttons.workflow_show')->makeFirst();
        });
        $this->crud->operation('list', function () {
            // $this->crud->addColumn(['name' => 'workflow', 'type' => 'workflow', 'label' => 'Workflow']);
            $this->addWorkflowPendingActionsFilter();
        });

        // Replaces Backpack's own show/update/delete buttons (same names,
        // so addButton()'s default $replaceExisting just swaps their
        // content) with per-row-gated versions — a node's row_actions
        // override (see Workflow\Support\WorkflowRowActions) can hide, say,
        // Delete once a record reaches an 'approved' state, independent of
        // the definition-level operation_settings applyWorkflowOperationAccess()
        // enforces for the whole operation. Registered here (queued after
        // ShowOperation/UpdateOperation/DeleteOperation's own 'list'/'show'
        // closures, since WorkflowOperation is used last) so it always runs
        // after the originals are added, not before.
        $this->crud->operation(['list', 'show'], function () {
            CRUD::addButton('line', 'show', 'view', 'workflow::buttons.workflow_gated_show');
            CRUD::addButton('line', 'update', 'view', 'workflow::buttons.workflow_gated_update');
            CRUD::addButton('line', 'delete', 'view', 'workflow::buttons.workflow_gated_delete');
        });

        $this->applyWorkflowOperationAccess();
    }

    /**
     * Moves the 'workflow' column to the end of the list columns, after
     * whatever the downstream CrudController's own setupListOperation()
     * adds. It has to be added early (see setupWorkflowOperationDefaults()
     * above — an operation('list') closure, so the column exists and the
     * "Pending my action" filter can be registered regardless of whether
     * the controller declares any columns of its own), but operation()
     * closures always run BEFORE the controller's own setupXxxOperation()
     * (see CrudController::setupConfigurationForCurrentOperation()'s own
     * docblock), so at add-time it's unavoidably first. afterOperationSetup()
     * is the one hook that runs after everything for the current operation
     * has been added, letting it move to the end instead — removed and
     * re-added, which is simpler than reordering by array key since the
     * column's own definition never changes.
     */
    protected function afterOperationSetup()
    {
        if ($this->crud->getCurrentOperation() === 'list') {
            // 'view_namespace' resolves this column's cell view from the
            // package's own `workflow::columns` namespace (see
            // resources/views/columns/workflow.blade.php's own docblock)
            // instead of the app's `crud::columns.workflow`.
            $this->crud->addColumn(['name' => 'status', 'type' => 'workflow', 'view_namespace' => 'workflow::columns', 'label' => 'Status']);
        }
    }

    /**
     * A "simple" (boolean toggle) filter, "Pending my action" — narrows the
     * list to only records whose active workflow instance currently has a
     * workflow_instance_pending_actors row matching the logged-in user
     * (directly by user id, or by any role/permission they hold). That table
     * is exactly the denormalized "who can act on this right now" index the
     * engine already maintains (see TransitionEngine::refreshPendingActors())
     * for this purpose, so the filter is a cheap indexed query rather than
     * evaluating every row's actor_rule live.
     */
    protected function addWorkflowPendingActionsFilter(): void
    {
        $this->crud->addFilter(
            ['name' => 'workflow_pending_action', 'type' => 'simple', 'label' => 'My Action'],
            false,
            function () {
                $ids = $this->workflowPendingActionWorkflowableIds();
                CRUD::addClause('whereIn', $this->crud->model->getKeyName(), $ids);
            }
        );
    }

    /**
     * @return array<int, int|string>
     */
    protected function workflowPendingActionWorkflowableIds(): array
    {
        $actor = backpack_auth()->user();

        if (! $actor) {
            return [];
        }

        $roleIds = method_exists($actor, 'roles') ? $actor->roles()->pluck('id')->all() : [];
        $permissionIds = method_exists($actor, 'permissions') ? $actor->permissions()->pluck('id')->all() : [];

        $instanceIds = \Workflow\Models\WorkflowInstancePendingActor::query()
            ->where(function ($query) use ($actor, $roleIds, $permissionIds) {
                $query->where(fn ($q) => $q->where('actor_type', 'user')->where('actor_id', $actor->getAuthIdentifier()));

                if (! empty($roleIds)) {
                    $query->orWhere(fn ($q) => $q->where('actor_type', 'role')->whereIn('actor_id', $roleIds));
                }

                if (! empty($permissionIds)) {
                    $query->orWhere(fn ($q) => $q->where('actor_type', 'permission')->whereIn('actor_id', $permissionIds));
                }
            })
            ->pluck('workflow_instance_id');

        return \Workflow\Models\WorkflowInstance::query()
            ->where('workflowable_type', get_class($this->crud->model))
            ->whereIn('id', $instanceIds)
            ->pluck('workflowable_id')
            ->all();
    }

    /**
     * Gates a single show/update/delete request for the one record it's
     * actually about, via Workflow\Support\WorkflowRowActions — which
     * already merges the record's current node's row_actions override (if
     * any) with the definition-level operation_settings from the toolbar's
     * gear-icon modal (node override wins outright when present; otherwise
     * the definition-level setting applies). Deliberately per-entry rather
     * than a blanket $crud->denyAccess($operation) for the whole operation:
     * a node override needs to be able to *re-enable* an operation the
     * definition-level setting disabled globally (e.g. Update disabled
     * everywhere except while a record is still in 'draft'), which a
     * blanket deny can never undo once applied. The row's own gated button
     * (crud/buttons/workflow_gated_*.blade.php) runs the exact same check
     * to decide whether to render at all, so a hidden button always means
     * the route itself is also enforced, and vice versa.
     *
     * 'create' is handled separately (WorkflowRowActions::allowedToCreate())
     * since there's no entry, and so no node, for a record that doesn't
     * exist yet — just the definition-level actor_rule. Registered for
     * BOTH 'list' and 'create' (not 'create' alone): denyAccess('create')
     * sets that operation's own access flag regardless of which operation
     * is actually current, but the closure itself only ever RUNS when its
     * own operation is current — so without 'list' here, the check never
     * fires on the list page at all, and Backpack's "Add" button (gated on
     * $crud->hasAccess('create'), independent of the current operation)
     * stays visible to everyone no matter what this denies.
     */
    protected function applyWorkflowOperationAccess(): void
    {
        $this->crud->operation(['show', 'update', 'delete'], function () {
            $operation = $this->crud->getCurrentOperation();
            $id = $this->crud->getCurrentEntryId();
            $entry = $id ? $this->crud->model->find($id) : null;

            if (! $entry) {
                return;
            }

            $allowed = app(\Workflow\Support\WorkflowRowActions::class)
                ->allowed($entry, $operation, backpack_auth()->user());

            if (! $allowed) {
                $this->crud->denyAccess($operation);
            }
        });

        $this->crud->operation(['list', 'create'], function () {
            $allowed = app(\Workflow\Support\WorkflowRowActions::class)
                ->allowedToCreate(get_class($this->crud->model), backpack_auth()->user());

            if (! $allowed) {
                $this->crud->denyAccess('create');
            }
        });
    }

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
            $this->crud->addButton('bottom', 'workflow_bulk_transition', 'view', 'workflow::buttons.workflow_bulk_transition');
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

            $edge = $instance->effectiveVersion()->edge($validated['edge_id']);
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
