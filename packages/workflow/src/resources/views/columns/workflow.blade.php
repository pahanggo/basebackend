{{--
    Generic "workflow" column: auto-added to any HasWorkflow CrudController's
    list operation (see WorkflowOperation::afterOperationSetup()) with
    'view_namespace' => 'workflow::columns' so Backpack's cell renderer
    (CrudPanel\Traits\Search::getCellViewName()) resolves this view from the
    package's own `workflow::` namespace instead of the app's `crud::` one —
    the same mechanism that lets this file live inside packages/workflow
    rather than the app's resources/views/crud. Shows a badge per active
    token (state) only — transitions themselves are fired from the dedicated
    show-workflow page (see buttons/workflow_show.blade.php), not from this
    column. A thin wrapper; the actual logic lives in
    Workflow\Support\WorkflowStatusPresenter, also shared with
    fields/workflow.blade.php so the two can't drift apart.
--}}
@php
    $workflowStatus = app(\Workflow\Support\WorkflowStatusPresenter::class)
        ->present($entry, backpack_auth()->user(), 'record_button');
@endphp

<div class="workflow-column d-flex flex-wrap" style="gap: .25rem; max-width: 260px;">
    @forelse ($workflowStatus['tokens'] as $token)
        @if(Str::endsWith($token['label'], ' (join)') === false)
            <div class="badge badge-warning">{{ $token['label'] }}</div>
        @endif
    @empty
        <div class="text-muted">—</div>
    @endforelse
</div>
