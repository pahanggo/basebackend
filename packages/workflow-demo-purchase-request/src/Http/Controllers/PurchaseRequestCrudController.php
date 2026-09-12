<?php

namespace WorkflowDemo\PurchaseRequest\Http\Controllers;

use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ShowOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;
use Workflow\Http\Controllers\Operations\WorkflowOperation;
use WorkflowDemo\PurchaseRequest\Http\Requests\PurchaseRequestFormRequest;
use WorkflowDemo\PurchaseRequest\Models\PurchaseRequest;

/**
 * The Backpack side of the Purchase Request demo — exercises the `workflow`
 * column/field and WorkflowBulkTransitionOperation against a real downstream
 * model, exactly as a project adopting this engine would wire up its own.
 */
class PurchaseRequestCrudController extends CrudController
{
    use ListOperation;
    use CreateOperation;
    use UpdateOperation;
    use ShowOperation;
    use DeleteOperation;
    use WorkflowOperation;

    public function setup(): void
    {
        CRUD::setModel(PurchaseRequest::class);
        CRUD::setRoute(config('backpack.base.route_prefix').'/purchase-requests');
        CRUD::setEntityNameStrings('purchase request', 'purchase requests');
    }

    protected function setupListOperation(): void
    {
        CRUD::addColumns($this->columns());
    }

    protected function setupShowOperation(): void
    {
        // ShowOperation defaults 'setFromDb' to true, which auto-adds a
        // column for every real DB column not already present under that
        // exact key — including 'requester_id', which Backpack's "_id"
        // heuristic then treats as a relation via the requester() method
        // (see the columns() comment below) and crashes resolving it.
        // Explicit columns only; no auto-detection needed here.
        CRUD::setOperationSetting('setFromDb', false);
        CRUD::addColumns($this->columns());
    }

    protected function columns(): array
    {
        return [
            ['name' => 'id', 'type' => 'text', 'label' => 'ID'],
            [
                // Not named 'requester' — Backpack auto-guesses a column
                // whose name matches a real model method as an eager-loadable
                // relation "entity" (see CrudPanel's makeSureColumnHasEntity),
                // which would force-eager-load it as a real Eloquent
                // relation and hit the same cross-connection problem
                // PurchaseRequest::requester()'s own docblock explains.
                'name' => 'requester_display', 'type' => 'closure', 'label' => 'Requester',
                'function' => fn (PurchaseRequest $entry) => $entry->requester()?->name ?? '—',
            ],
            ['name' => 'amount', 'type' => 'number', 'decimals' => 2, 'prefix' => 'RM '],
            ['name' => 'purpose', 'type' => 'text', 'limit' => 40],
        ];
    }

    protected function setupCreateOperation(): void
    {
        CRUD::setValidation(PurchaseRequestFormRequest::class);

        // 'entity' => false is required here: Backpack's field-guessing
        // strips a trailing "_id" and, finding a `requester()` method on the
        // model, would otherwise treat this as a relation field and try to
        // build a relation instance out of it — but requester() is a plain
        // lookup (see its own docblock), not an Eloquent relation.
        CRUD::addField(['name' => 'requester_id', 'type' => 'hidden', 'entity' => false, 'value' => backpack_auth()->id()]);
        CRUD::addField(['name' => 'purpose', 'type' => 'textarea']);
        CRUD::addField(['name' => 'amount', 'type' => 'number']);
    }

    protected function setupUpdateOperation(): void
    {
        $this->setupCreateOperation();

        // 'view_namespace' resolves this field's view from the package's own
        // `workflow::fields` namespace (see resources/views/fields/workflow.blade.php's
        // own docblock) instead of the app's `crud::fields.workflow`.
        CRUD::addField(['name' => 'workflow', 'type' => 'workflow', 'view_namespace' => 'workflow::fields', 'label' => 'Workflow']);
        CRUD::addField(['name' => 'hod_remarks', 'type' => 'textarea', 'readonly' => true]);
        CRUD::addField(['name' => 'marketing_feedback', 'type' => 'textarea', 'readonly' => true]);
        CRUD::addField(['name' => 'technical_feedback', 'type' => 'textarea', 'readonly' => true]);
        CRUD::addField(['name' => 'operations_feedback', 'type' => 'textarea', 'readonly' => true]);
        CRUD::addField(['name' => 'finance_remarks', 'type' => 'textarea', 'readonly' => true]);
        CRUD::addField(['name' => 'rejection_reason', 'type' => 'textarea', 'readonly' => true]);
    }
}
