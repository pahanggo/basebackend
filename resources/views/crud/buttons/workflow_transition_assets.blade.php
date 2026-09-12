{{--
    Carries the shared "workflow" column's transition modal + its JS —
    deliberately NOT part of columns/workflow.blade.php itself.

    Backpack's list page renders row content (and therefore every column,
    including "workflow") exclusively through DataTables' AJAX /search
    endpoint, which DataTables inserts into each <td> via .html()/innerHTML —
    and a <script> tag inserted that way never executes (a basic browser
    security behavior, not a Backpack quirk). A column-embedded <script>
    silently never runs, which is exactly what made the transition buttons
    that need input capture or confirmation "do nothing" when clicked: the
    onclick="" HTML attribute itself still fires fine (inline attribute
    handlers, unlike <script> tags, do work when injected via innerHTML), but
    the function it called was never defined.

    Buttons, unlike columns, render once as part of the actual static list
    page load (see crud::inc.button_stack) — so a <script> living here
    genuinely executes. Register this alongside the 'workflow' column in the
    CrudController's setupListOperation():
        CRUD::addButton('top', 'workflow_transition_assets', 'view', 'crud::buttons.workflow_transition_assets');
    It renders no visible button itself — just the shared modal markup and
    the JS every "workflow" column's transition buttons call into.
--}}
@if ($crud->fieldTypeNotLoaded('workflow-transition-assets'))
    @php $crud->markFieldTypeAsLoaded('workflow-transition-assets'); @endphp

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

    @push('after_scripts')
    <script>
        window.wfTransitionForm = null;

        window.openWorkflowTransitionModal = function (button) {
            const form = button.closest('.workflow-transition-form');
            const transition = JSON.parse(form.dataset.transition);

            // Nothing to ask the user — fire immediately, same as a plain
            // submit button, instead of an empty "click Confirm" modal.
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
    @endpush
@endif
