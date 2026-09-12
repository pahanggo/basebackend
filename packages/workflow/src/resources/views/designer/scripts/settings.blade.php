<script>
    // The definition-level settings modal: start state (moved off the
    // toolbar) plus the create/show/update/delete operation gates (enabled +
    // who-can actor_rule) that WorkflowOperation::applyWorkflowOperationAccess()
    // enforces server-side. Rendered as a raw HTML string into #wf-settings-body
    // (same convention as the node/edge inspector in inspector.blade.php).
    // The actor_rule pickers themselves reuse WF_INSPECTOR_MIXIN's shared
    // actorRuleField()/initActorSelect2() (same "Roles/Permissions/Users/
    // Callbacks" grouped select2 the edge inspector and a node's row_actions
    // use) rather than a separate implementation.
    // Merged onto the main workflowDesigner() Alpine component in edit.blade.php.
    const WF_SETTINGS_MIXIN = {
            openSettingsModal() {
                this.renderSettingsModal();
                $('#wf-settings-modal').modal('show');
                this.$nextTick(() => this.initActorSelect2());
            },

            renderSettingsModal() {
                const container = document.getElementById('wf-settings-body');
                if (! container) return;

                let html = `<div class="form-group">
                    <label class="mb-1 small font-weight-bold">Name</label>
                    <input type="text" class="form-control form-control-sm" value="${this.graph.display_name ?? ''}"
                        onchange="Alpine.$data(document.querySelector('[x-data]')).setDisplayName(this.value)">
                    <small class="form-text text-muted">Shown on the show-workflow page header. Defaults to the model name.</small>
                </div>
                <div class="form-group">
                    <label class="mb-1 small font-weight-bold">Start state</label>
                    <select class="form-control form-control-sm" style="width: auto" onchange="Alpine.$data(document.querySelector('[x-data]')).setStartState(this.value)">
                        <option value="">— none —</option>
                        ${this.graph.nodes.map(n => `<option value="${n.id}" ${n.id === this.graph.start ? 'selected' : ''}>${n.name || n.id}</option>`).join('')}
                    </select>
                </div>
                <hr>`;

                const labels = { create: 'Create', show: 'Show', update: 'Update', delete: 'Delete' };

                ['create', 'show', 'update', 'delete'].forEach(op => {
                    const setting = this.graph.operation_settings[op];
                    html += `<div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="wf-op-enabled-${op}" ${setting.enabled ? 'checked' : ''}
                                onchange="Alpine.$data(document.querySelector('[x-data]')).toggleOperationEnabled('${op}', this.checked)">
                            <label class="form-check-label" for="wf-op-enabled-${op}">${labels[op]}</label>
                        </div>`;

                    if (setting.enabled) {
                        html += `<div class="ml-4 mt-1">
                            <label class="mb-1 small">Who can ${labels[op].toLowerCase()} (leave blank to allow anyone with the base permission)</label>
                            ${this.actorRuleField(setting.actor_rule, `graph.operation_settings.${op}.actor_rule`)}
                        </div>`;
                    }

                    html += `</div>`;
                });

                container.innerHTML = html;
            },

            setStartState(value) {
                this.graph.start = value;
            },

            setDisplayName(value) {
                this.graph.display_name = value;
            },

            toggleOperationEnabled(op, checked) {
                this.graph.operation_settings[op].enabled = checked;
                this.renderSettingsModal();
                this.$nextTick(() => this.initActorSelect2());
            },
    };
</script>
