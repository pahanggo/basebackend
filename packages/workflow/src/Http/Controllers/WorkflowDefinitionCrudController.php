<?php

namespace Workflow\Http\Controllers;

use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;
use Workflow\Http\Requests\WorkflowDefinitionRequest;
use Workflow\Models\WorkflowDefinition;

/**
 * Lists and manages workflow templates. Ships from the package itself — a
 * downstream app needs zero app/ files for this to work out of the box; it
 * only needs to extend this controller if it wants to customize it, the same
 * way app/Http/Controllers/Auth/PermissionCrudController.php extends
 * behavior from packages/backpack/permissionmanager.
 */
class WorkflowDefinitionCrudController extends CrudController
{
    use \Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\ShowOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;

    public function setup(): void
    {
        CRUD::setModel(WorkflowDefinition::class);
        CRUD::setRoute(config('backpack.base.route_prefix').'/workflows/definitions');
        CRUD::setEntityNameStrings('workflow', 'workflows');
    }

    protected function setupListOperation(): void
    {
        CRUD::addColumn(['name' => 'name', 'type' => 'text']);
        CRUD::addColumn(['name' => 'slug', 'type' => 'text']);
        CRUD::addColumn(['name' => 'model', 'type' => 'text', 'label' => 'Target model']);
        CRUD::addColumn([
            'name' => 'published_version_id',
            'type' => 'closure',
            'label' => 'Published version',
            'function' => fn (WorkflowDefinition $entry) => $entry->publishedVersion?->version ?? '—',
        ]);

        CRUD::addButton('line', 'design', 'view', 'workflow::buttons.designer')->makeFirst();
    }

    protected function setupShowOperation(): void
    {
        $this->setupListOperation();
    }

    protected function setupCreateOperation(): void
    {
        CRUD::setValidation(WorkflowDefinitionRequest::class);

        CRUD::addField(['name' => 'name', 'type' => 'text']);
        CRUD::addField(['name' => 'slug', 'type' => 'slug', 'target' => 'name', 'hint' => 'Used by HasWorkflow::workflowDefinitionSlug() on the target model.']);
        // 'view_namespace' resolves this field's view from the package's own
        // `workflow::fields` namespace (see resources/views/fields/model_picker.blade.php)
        // instead of the app's `crud::fields.model_picker`.
        CRUD::addField(['name' => 'model', 'type' => 'model_picker', 'view_namespace' => 'workflow::fields', 'label' => 'Target model', 'hint' => 'Any concrete Eloquent model under app/Models.']);
        CRUD::addField(['name' => 'description', 'type' => 'textarea']);
    }

    protected function setupUpdateOperation(): void
    {
        $this->setupCreateOperation();
    }
}
