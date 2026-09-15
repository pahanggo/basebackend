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
                this.$nextTick(() => this.reinitSelect2Widgets());
            },

            renderSettingsModal() {
                const container = document.getElementById('wf-settings-body');
                if (! container) return;

                let html = `<div class="row"><div class="col-sm-6"><div class="form-group">
                    <label class="mb-1 small font-weight-bold">Name</label>
                    <input type="text" class="form-control form-control-sm" value="${this.graph.display_name ?? ''}"
                        onchange="Alpine.$data(document.querySelector('[x-data]')).setDisplayName(this.value)">
                    <small class="form-text text-muted">Shown on the show-workflow page header. Defaults to the model name.</small>
                </div></div><div class="col-sm-6">
                <div class="form-group">
                    <label class="mb-1 small font-weight-bold">Start state</label>
                    <select class="form-control form-control-sm" onchange="Alpine.$data(document.querySelector('[x-data]')).setStartState(this.value)">
                        <option value="">— none —</option>
                        ${this.graph.nodes.map(n => `<option value="${n.id}" ${n.id === this.graph.start ? 'selected' : ''}>${n.name || n.id}</option>`).join('')}
                    </select>
                </div>
                </div>
                </div>
                <hr>
                <label class="mb-1 small font-weight-bold">Actions</label>
                <p class="text-muted small mb-2">
                    Who can create, show, update, or delete records. Enable and leave blank to allow anyone with the base permission.
                </p>
                <div class="row">`;

                const labels = { create: 'Create', show: 'Show', update: 'Update', delete: 'Delete' };

                ['create', 'show', 'update', 'delete'].forEach(op => {
                    const setting = this.graph.operation_settings[op];
                    html += `<div class="col-sm-6"><div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="wf-op-enabled-${op}" ${setting.enabled ? 'checked' : ''}
                                onchange="Alpine.$data(document.querySelector('[x-data]')).toggleOperationEnabled('${op}', this.checked)">
                            <label class="mb-1 small font-weight-bold" for="wf-op-enabled-${op}">${labels[op]}</label>
                        </div>`;

                    if (setting.enabled) {
                        html += `<div class="ml-4 mt-1">
                            <label class="mb-1 small text-muted">Who can ${labels[op].toLowerCase()}</label>
                            ${this.actorRuleField(setting.actor_rule, `graph.operation_settings.${op}.actor_rule`)}
                        </div>`;
                    }

                    html += `</div></div>`;
                });

                html += `</div><hr>
                    <label class="mb-1 small font-weight-bold">List visibility</label>
                    <p class="text-muted small mb-2">
                        Who sees which records in the list — separate from Show/Update/Delete above. First matching rule wins; empty means no restriction. Once a rule exists, unmatched actors see nothing.
                    </p>`;

                (this.graph.visibility_rules || []).forEach((rule, i) => {
                    html += this.renderVisibilityRuleRow(rule, i);
                });

                html += `<button type="button" class="btn btn-sm btn-outline-secondary"
                    onclick="Alpine.$data(document.querySelector('[x-data]')).addVisibilityRule()">
                    <i class="la la-plus"></i> Add rule
                </button>`;

                container.innerHTML = html;
            },

            renderVisibilityRuleRow(rule, i) {
                const scope = rule.scope || 'all';
                const last = (this.graph.visibility_rules || []).length - 1;

                let html = `<div class="wf-repeatable-row">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <label class="mb-0 small font-weight-bold">Who</label>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" ${i === 0 ? 'disabled' : ''}
                                onclick="Alpine.$data(document.querySelector('[x-data]')).moveVisibilityRule(${i}, -1)"><i class="la la-arrow-up"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" ${i === last ? 'disabled' : ''}
                                onclick="Alpine.$data(document.querySelector('[x-data]')).moveVisibilityRule(${i}, 1)"><i class="la la-arrow-down"></i></button>
                            ${this.removeButton(`removeVisibilityRule(${i})`)}
                        </div>
                    </div>
                    ${this.actorRuleField(rule.actor_rule, `graph.visibility_rules.${i}.actor_rule`)}
                    <label class="mb-1 small">Sees</label>
                    <select class="form-control form-control-sm mb-2"
                        onchange="Alpine.$data(document.querySelector('[x-data]')).setVisibilityRuleScope(${i}, this.value)">
                        <option value="all" ${scope === 'all' ? 'selected' : ''}>All records</option>
                        <option value="owner" ${scope === 'owner' ? 'selected' : ''}>Only their own</option>
                        <option value="model_callback" ${scope === 'model_callback' ? 'selected' : ''}>Custom (a model method)</option>
                    </select>`;

                if (scope === 'owner') {
                    html += this.textField(`graph.visibility_rules.${i}.owner_field`, 'owner field (e.g. requester_id)', rule.owner_field || '');
                } else if (scope === 'model_callback') {
                    html += this.modelCallbackField(`graph.visibility_rules.${i}.model_callback`, rule.model_callback || '', 'method (receives $query, $actor)');
                }

                html += `</div>`;
                return html;
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
                this.$nextTick(() => this.reinitSelect2Widgets());
            },

            addVisibilityRule() {
                this.graph.visibility_rules.push({ actor_rule: null, scope: 'all', owner_field: '', model_callback: '' });
                this.renderSettingsModal();
                this.$nextTick(() => this.reinitSelect2Widgets());
            },

            // Not the generic removeAt() other repeatable lists use — that
            // one finishes with rerenderInspector(), which re-triggers the
            // node/edge inspector panel's own Alpine reactivity, not this
            // modal's plain innerHTML render. Without a dedicated handler
            // calling renderSettingsModal() instead, the row would vanish
            // from the underlying data but stay visible on screen until
            // something else happened to re-render the modal.
            removeVisibilityRule(i) {
                this.graph.visibility_rules.splice(i, 1);
                this.renderSettingsModal();
                this.$nextTick(() => this.reinitSelect2Widgets());
            },

            setVisibilityRuleScope(i, value) {
                this.graph.visibility_rules[i].scope = value;
                this.renderSettingsModal();
                this.$nextTick(() => this.reinitSelect2Widgets());
            },

            moveVisibilityRule(i, direction) {
                const rules = this.graph.visibility_rules;
                const j = i + direction;
                if (j < 0 || j >= rules.length) return;
                [rules[i], rules[j]] = [rules[j], rules[i]];
                this.renderSettingsModal();
                this.$nextTick(() => this.reinitSelect2Widgets());
            },
    };
</script>
