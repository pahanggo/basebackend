{{--
    Generic "workflow" field: drop `['name' => 'id', 'type' => 'workflow', 'label' => 'Workflow']`
    onto a create/update form for any model that `use HasWorkflow`. Shows a
    badge per active token (state) plus the names of transitions currently
    available to the logged-in user — read-only, unlike the matching
    "workflow" column.

    Deliberately not interactive here: this partial renders inside the
    edit/create page's own <form>, and a transition button needs its own
    <form> (see columns/workflow.blade.php) — nesting a <form> inside another
    is invalid HTML that browsers silently reparent, which would corrupt the
    surrounding record-edit form's submit behavior. Fire transitions from the
    list view's "workflow" column (or a bulk action) instead; this field is
    purely informational, e.g. so a reviewer editing the record can see what
    state it's in and what they'll be able to do next without leaving the
    page. Shares its logic with columns/workflow.blade.php via
    Workflow\Support\WorkflowStatusPresenter so the two can't drift apart.
--}}
@php
    $workflowStatus = app(\Workflow\Support\WorkflowStatusPresenter::class)
        ->present($entry, backpack_auth()->user(), 'record_button');

    $field['wrapper'] = $field['wrapper'] ?? $field['wrapperAttributes'] ?? [];
    $field['wrapper']['data-field-type'] = 'workflow';
    $field['wrapper']['data-field-name'] = $field['name'];
@endphp

@include('crud::fields.inc.wrapper_start')
    <label class="mb-1 font-weight-normal d-block">{!! $field['label'] !!}</label>

    @forelse ($workflowStatus['tokens'] as $token)
        <span class="badge badge-info">{{ $token['label'] }}</span>
    @empty
        <span class="text-muted">No active workflow instance.</span>
    @endforelse

    @if ($workflowStatus['transitions'])
        <p class="text-muted small mt-2 mb-0">
            Available next: {{ collect($workflowStatus['transitions'])->pluck('label')->implode(', ') }}
            — use the list view to trigger a transition.
        </p>
    @endif

    @if (isset($field['hint']))
        <p class="help-block">{!! $field['hint'] !!}</p>
    @endif
@include('crud::fields.inc.wrapper_end')
