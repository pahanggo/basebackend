{{--
    Wraps the standard 'update' button with a per-row check — see
    workflow_gated_show.blade.php's docblock for the full explanation;
    same pattern, gating Workflow\Support\WorkflowRowActions's 'update' check.
--}}
@if (app(\Workflow\Support\WorkflowRowActions::class)->allowed($entry, 'update', backpack_auth()->user()))
    @include('crud::buttons.update')
@endif
