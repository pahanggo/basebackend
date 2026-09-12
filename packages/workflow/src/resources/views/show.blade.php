{{--
    The "show-workflow" page — linked to from the generic workflow_show
    button in any HasWorkflow CrudController's actions column (see
    crud/buttons/workflow_show.blade.php at the app level, and
    WorkflowOperation::setupWorkflowOperationDefaults() where it's
    registered). Renders, in order: the current node's header_view, its
    field policy as a plain read-only column list (in the exact order
    configured in the node inspector's field-policy editor — this is a
    summary view, not a Backpack edit form), the current node's footer_view,
    then the record_button-surfaced transitions available to the logged-in
    user at the bottom.
--}}
@extends(backpack_view('blank'))

@section('header')
    <section class="container-fluid d-print-none mb-4 mt-3">
        <h2>
            <span>{{ $displayName }} #{{ $workflowable->getKey() }}</span>
            <small>Workflow</small>
        </h2>
    </section>
@endsection

@php
    $workflowTimeline = app(\Workflow\Support\WorkflowTimeline::class)->build($workflowable);
@endphp

@section('content')
    <div class="row">
        <div class="{{ $workflowTimeline ? 'col-md-8' : 'col-md-12' }}">
            @if (! empty($node['header_view']) && view()->exists($node['header_view']))
                @include($node['header_view'], ['entry' => $workflowable])
            @endif
            @if ($node === null)
                <p class="text-muted mb-0">This record has no active workflow instance.</p>
            @else
                @if ($columns->isEmpty())
                    <p class="text-muted">No fields configured for this state.</p>
                @else
                    <table class="table table-bordered table-striped">
                        <tbody>
                            @foreach ($columns as $column)
                                <tr>
                                    <td style="width: 30%"><strong>{{ $column['label'] }}</strong></td>
                                    <td>
                                        @if (view()->exists('vendor.backpack.crud.columns.' . $column['type']))
                                            @include('vendor.backpack.crud.columns.' . $column['type'], ['entry' => $workflowable])
                                        @elseif (view()->exists('crud::columns.' . $column['type']))
                                            @include('crud::columns.' . $column['type'], ['entry' => $workflowable])
                                        @else
                                            @include('crud::columns.text', ['entry' => $workflowable])
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endif

            @if (! empty($node['footer_view']) && view()->exists($node['footer_view']))
                @include($node['footer_view'], ['entry' => $workflowable])
            @endif
        </div>

        @if ($workflowTimeline)
            <div class="col-md-4">
                @include('workflow::inc.workflow_timeline', ['entry' => $workflowable])
            </div>
        @endif
    </div>
    <div class="row">
        <div class="col-12">
            <div class="bg-white p-3">
                @if ($node != null)
                    @if (count($status['transitions']))
                        <h6 class="text-muted">Next actions</h6>
                        @foreach ($status['transitions'] as $transition)
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
                                <input type="hidden" name="workflowable_type" value="{{ get_class($workflowable) }}">
                                <input type="hidden" name="workflowable_id" value="{{ $workflowable->getKey() }}">
                                <input type="hidden" name="edge_id" value="{{ $transition['edge_id'] }}">
                                @if ($returnTo)
                                    <input type="hidden" name="return_to" value="{{ $returnTo }}">
                                @endif
                                <button type="button" class="btn btn-primary mr-1 mb-2" onclick="openWorkflowTransitionModal(this)">{{ $transition['label'] }}</button>
                            </form>
                        @endforeach
                    @endif
                @endif
            </div>
        </div>
    </div>

    <div class="modal" id="wf-transition-modal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="wf-transition-modal-title"></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body" id="wf-transition-modal-body"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="submitWorkflowTransitionModal()">Confirm</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('after_scripts')
    <script>
        window.wfTransitionForm = null;

        window.openWorkflowTransitionModal = function (button) {
            const form = button.closest('.workflow-transition-form');
            const transition = JSON.parse(form.dataset.transition);

            if (! transition.requires_confirmation && ! (transition.inputs || []).length) {
                form.submit();
                return;
            }

            window.wfTransitionForm = form;

            document.getElementById('wf-transition-modal-title').textContent = transition.label;

            let body = '';
            if (transition.requires_confirmation) {
                body += '<p class="mb-3" style="font-size: 1rem">Are you sure you want to do this?</p>';
            }
            (transition.inputs || []).forEach((input, i) => {
                const label = input.name.replace(/_/g, ' ');
                const required = input.required ? 'required' : '';
                if (input.type === 'textarea') {
                    body += `<div class="form-group"><label class="mb-1 small font-weight-bold">${label}</label><textarea class="form-control" id="wf-transition-input-${i}" data-input-name="${input.name}" rows="3" ${required}></textarea></div>`;
                } else if (input.type === 'checkbox') {
                    body += `<div class="form-group form-check"><input type="checkbox" class="form-check-input" id="wf-transition-input-${i}" data-input-name="${input.name}"><label class="form-check-label small" for="wf-transition-input-${i}">${label}</label></div>`;
                } else {
                    const type = ['date', 'number'].includes(input.type) ? input.type : 'text';
                    body += `<div class="form-group"><label class="mb-1 small font-weight-bold">${label}</label><input type="${type}" class="form-control" id="wf-transition-input-${i}" data-input-name="${input.name}" ${required}></div>`;
                }
            });

            document.getElementById('wf-transition-modal-body').innerHTML = body
                || '<p class="text-muted mb-0 small">No further details needed — click Confirm to proceed.</p>';

            $('#wf-transition-modal').modal('show');
        };

        window.submitWorkflowTransitionModal = function () {
            const form = window.wfTransitionForm;
            if (! form) return;

            const inputEls = document.querySelectorAll('#wf-transition-modal-body [data-input-name]');
            for (const el of inputEls) {
                if (el.hasAttribute('required') && el.type !== 'checkbox' && ! el.value) {
                    el.classList.add('is-invalid');
                    return;
                }
                const value = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'inputs[' + el.dataset.inputName + ']';
                hidden.value = value;
                form.appendChild(hidden);
            }

            $('#wf-transition-modal').modal('hide');
            form.submit();
        };
    </script>
@endsection
