{{--
    Wraps the standard 'show' button with a per-row check: the current
    node's row_actions.show override (if any), falling back to the
    definition-level operation_settings.show setting (already enforced at
    the whole-operation level by
    Workflow\Http\Controllers\Operations\WorkflowOperation::applyWorkflowOperationAccess())
    when this row's node declares no override of its own. See
    Workflow\Support\WorkflowRowActions.

    Registered under the same name ('show') as Backpack's own button, so it
    replaces it in place — see WorkflowOperation::setupWorkflowOperationDefaults().
--}}
@if (app(\Workflow\Support\WorkflowRowActions::class)->allowed($entry, 'show', backpack_auth()->user()))
    @include('crud::buttons.show')
@endif
