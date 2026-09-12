{{--
    Wraps the standard 'delete' button with a per-row check — see
    workflow_gated_show.blade.php's docblock for the full explanation;
    same pattern, gating Workflow\Support\WorkflowRowActions's 'delete' check.
--}}
@if (app(\Workflow\Support\WorkflowRowActions::class)->allowed($entry, 'delete', backpack_auth()->user()))
    @include('crud::buttons.delete')
@endif
