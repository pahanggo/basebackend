{{--
    Generic "workflow" column: drop `['name' => 'id', 'type' => 'workflow', 'label' => 'Workflow']`
    onto any CRUD whose model `use HasWorkflow`. Shows a badge per active
    token (state) plus a button for each manual, record_button-surfaced
    transition available to the logged-in user (actor_rule- and
    precondition-checked). See packages/workflow's HasWorkflow trait and
    Support\{ActorRuleResolver,PreconditionEvaluator}.

    This Blade file lives at the app level (not inside packages/workflow)
    because this fork of Backpack CRUD always resolves a column/field's
    `type` against the app's own resources/views/crud — see
    BackpackServiceProvider::loadViewsWithFallbacks(). The file itself stays
    a thin wrapper; all the actual logic below is package-provided.
--}}
@php
    $workflowInstance = method_exists($entry, 'workflowInstance') ? $entry->workflowInstance() : null;
    $currentUser = backpack_auth()->user();
    $actorRules = app(\Workflow\Support\ActorRuleResolver::class);
    $preconditions = app(\Workflow\Support\PreconditionEvaluator::class);

    $availableEdges = [];
    if ($workflowInstance) {
        $version = $workflowInstance->version;
        foreach ($workflowInstance->activeTokens as $token) {
            foreach ($version->edgesFrom($token->node_id) as $edge) {
                if (($edge['trigger'] ?? 'manual') !== 'manual') {
                    continue;
                }
                if (! in_array('record_button', $edge['surfaces'] ?? ['record_button'])) {
                    continue;
                }
                if (! $actorRules->allows($edge['actor_rule'] ?? null, $currentUser)) {
                    continue;
                }
                if (! $preconditions->passes($edge['preconditions'] ?? null, $entry)) {
                    continue;
                }
                $availableEdges[] = $edge;
            }
        }
    }
@endphp

<div class="workflow-column">
    @if ($workflowInstance)
        @foreach ($workflowInstance->activeTokens as $token)
            <span class="badge badge-info">{{ $token->node_id }}</span>
        @endforeach
    @else
        <span class="text-muted">—</span>
    @endif

    @foreach ($availableEdges as $edge)
        <form method="POST"
              action="{{ route('workflow.transition') }}"
              class="d-inline workflow-transition-form"
              data-requires-confirmation="{{ $edge['requires_confirmation'] ?? false ? '1' : '0' }}"
              data-inputs="{{ collect($edge['inputs'] ?? [])->pluck('name')->implode(',') }}"
              data-edge-id="{{ $edge['id'] }}">
            @csrf
            <input type="hidden" name="workflowable_type" value="{{ get_class($entry) }}">
            <input type="hidden" name="workflowable_id" value="{{ $entry->getKey() }}">
            <input type="hidden" name="edge_id" value="{{ $edge['id'] }}">
            <button type="submit" class="btn btn-xs btn-outline-secondary">{{ $edge['id'] }}</button>
        </form>
    @endforeach
</div>

@if ($crud->fieldTypeNotLoaded('workflow-column-script'))
    @php $crud->markFieldTypeAsLoaded('workflow-column-script'); @endphp
    @push('crud_list_scripts')
    <script>
        if (typeof window.workflowTransitionFormsBound === 'undefined') {
            window.workflowTransitionFormsBound = true;

            document.addEventListener('submit', function (event) {
                var form = event.target.closest('.workflow-transition-form');
                if (! form) {
                    return;
                }

                if (form.dataset.requiresConfirmation === '1' && ! window.confirm('Are you sure?')) {
                    event.preventDefault();
                    return;
                }

                var inputNames = (form.dataset.inputs || '').split(',').filter(Boolean);
                for (var i = 0; i < inputNames.length; i++) {
                    var value = window.prompt(inputNames[i] + ':');
                    if (value === null) {
                        event.preventDefault();
                        return;
                    }
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'inputs[' + inputNames[i] + ']';
                    hidden.value = value;
                    form.appendChild(hidden);
                }
            });
        }
    </script>
    @endpush
@endif
