{{--
    Generic "workflow" column: drop `['name' => 'id', 'type' => 'workflow', 'label' => 'Workflow']`
    onto any CRUD whose model `use HasWorkflow`. Shows a badge per active
    token (state) plus a button for each manual, record_button-surfaced
    transition available to the logged-in user (actor_rule- and
    precondition-checked). See packages/workflow's HasWorkflow trait and
    Support\WorkflowStatusPresenter.

    This Blade file lives at the app level (not inside packages/workflow)
    because this fork of Backpack CRUD always resolves a column/field's
    `type` against the app's own resources/views/crud — see
    BackpackServiceProvider::loadViewsWithFallbacks(). The file itself stays
    a thin wrapper; all the actual logic lives in WorkflowStatusPresenter,
    shared with fields/workflow.blade.php so the two never drift apart.

    Confirmation and transition-time inputs are captured via a shared modal
    (openWorkflowTransitionModal/submitWorkflowTransitionModal), not
    window.confirm()/window.prompt() — those are silently blocked/dismissed
    in some browser contexts. That modal + its JS deliberately do NOT live
    in this file: Backpack renders every row (and therefore this column)
    exclusively through DataTables' AJAX /search endpoint, and a <script>
    tag inserted via innerHTML (how DataTables places cell content) never
    executes — a column-embedded <script> here would silently never run,
    which is exactly the bug this replaced. See
    crud/buttons/workflow_transition_assets.blade.php (a real page-load
    button-stack entry, not ajax-rendered row content) for where that JS
    actually lives — register it once per CrudController alongside this
    column:
        CRUD::addButton('top', 'workflow_transition_assets', 'view', 'crud::buttons.workflow_transition_assets');
--}}
@php
    $workflowStatus = app(\Workflow\Support\WorkflowStatusPresenter::class)
        ->present($entry, backpack_auth()->user(), 'record_button');
@endphp

<div class="workflow-column">
    @forelse ($workflowStatus['tokens'] as $token)
        <span class="badge badge-info">{{ $token['label'] }}</span>
    @empty
        <span class="text-muted">—</span>
    @endforelse

    @foreach ($workflowStatus['transitions'] as $transition)
        <form method="POST"
              action="{{ route('workflow.transition') }}"
              class="d-inline workflow-transition-form"
              data-transition="{{ json_encode([
                  'edge_id' => $transition['edge_id'],
                  'label' => $transition['label'],
                  'requires_confirmation' => $transition['requires_confirmation'],
                  'inputs' => $transition['inputs'],
              ]) }}">
            @csrf
            <input type="hidden" name="workflowable_type" value="{{ get_class($entry) }}">
            <input type="hidden" name="workflowable_id" value="{{ $entry->getKey() }}">
            <input type="hidden" name="edge_id" value="{{ $transition['edge_id'] }}">
            <button type="button" class="btn btn-xs btn-outline-secondary" onclick="openWorkflowTransitionModal(this)">{{ $transition['label'] }}</button>
        </form>
    @endforeach
</div>
