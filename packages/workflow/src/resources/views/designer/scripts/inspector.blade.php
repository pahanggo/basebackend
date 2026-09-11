<script>
    // Node/edge inspector: renders the inspector panel's HTML (raw
    // innerHTML + delegated onclick/onchange handlers, not nested Alpine
    // templates — the precondition tree is genuinely recursive and
    // Alpine's <template> directives don't recurse cleanly), the
    // actor_rule/model_callback select2 pickers, the small form-control
    // helpers, and the path-based mutation helpers (setPath/removeAt/etc)
    // those handlers call into. Merged onto the main workflowDesigner()
    // Alpine component in edit.blade.php.
    const WF_INSPECTOR_MIXIN = {
            renderNodeInspector() {
                const node = this.findNode(this.selected.id);
                if (! node) return '';
                let html = `<h5>Node: ${node.id}</h5>`;
                html += this.textField('node.name', 'name', node.name);
                html += this.selectField('node.type', 'type', node.type, ['state', 'fork', 'join']);
                if (node.type === 'state') {
                    const fpCount = (node.field_policy || []).length;
                    html += `<label class="mt-3 mb-1 d-block small">Field policy</label>`;
                    html += `<p class="text-muted small">${fpCount} field${fpCount === 1 ? '' : 's'} configured. Fields not listed fall back to readonly.</p>`;
                    html += `<button type="button" class="btn btn-sm btn-outline-primary" onclick="Alpine.$data(document.querySelector('[x-data]')).openFieldPolicyModal()"><i class="la la-table"></i> Edit field policy</button>`;

                    html += `<label class="mt-3 mb-1 d-block small">Form header/footer</label>`;
                    html += this.textField('node.header_view', 'header_view', node.header_view);
                    html += this.textField('node.footer_view', 'footer_view', node.footer_view);
                }
                html += `<div class="mt-3"><button type="button" class="btn btn-sm btn-danger" onclick="Alpine.$data(document.querySelector('[x-data]')).removeSelectedNode()">Delete node</button></div>`;
                return html;
            },

            renderEdgeInspector() {
                const edge = this.findEdge(this.selected.id);
                if (! edge) return '';
                const fromName = this.findNode(edge.from)?.name || edge.from;
                const toName = this.findNode(edge.to)?.name || edge.to;
                let html = `<h5>Connection: ${edge.id}</h5>`;
                html += `<p class="small text-muted">${fromName} &rarr; ${toName}</p>`;
                html += this.textField('edge.name', 'name', edge.name);
                html += this.selectField('edge.trigger', 'trigger', edge.trigger, ['manual', 'automatic', 'webhook', 'timer']);

                if (edge.trigger === 'manual') {
                    html += `<label class="mt-2 mb-1 d-block small">Who can trigger this</label>`;
                    html += this.actorRuleField(edge);
                    html += this.modelCallbackField('edge.actor_rule.model_callback', edge.actor_rule.model_callback || '', 'Model callback (optional — combined with roles/permissions/users below)');
                    html += this.selectField('edge.actor_rule.match', 'match', edge.actor_rule.match, ['any', 'all']);

                    html += `<div class="form-check mt-2">
                        <input type="checkbox" class="form-check-input" id="wf-confirm" ${edge.requires_confirmation ? 'checked' : ''} onchange="Alpine.$data(document.querySelector('[x-data]')).setPath('edge.requires_confirmation', this.checked)">
                        <label class="form-check-label small" for="wf-confirm">Requires confirmation</label>
                    </div>`;

                    html += `<label class="mt-2 mb-1 d-block small">Surfaces</label>`;
                    ['record_button', 'bulk_action'].forEach(s => {
                        const checked = (edge.surfaces || []).includes(s);
                        html += `<div class="form-check form-check-inline small">
                            <input type="checkbox" class="form-check-input" id="wf-surface-${s}" ${checked ? 'checked' : ''} onchange="Alpine.$data(document.querySelector('[x-data]')).toggleSurface('${s}', this.checked)">
                            <label class="form-check-label" for="wf-surface-${s}">${s}</label>
                        </div>`;
                    });

                    html += `<p class="text-muted small mt-1 mb-0">Leave blank to fall back to the connection name.</p>`;
                    html += this.textField('edge.button_label', 'Button label', edge.button_label);
                }

                html += `<hr><label class="mb-1 d-block small">Preconditions</label>`;
                html += this.renderPreconditionGroup(edge.preconditions, 'edge.preconditions');

                html += `<hr><label class="mb-1 d-block small">Actions</label>`;
                (edge.actions || []).forEach((action, i) => html += this.renderActionRow(action, i));
                html += `<button type="button" class="btn btn-sm btn-outline-primary" onclick="Alpine.$data(document.querySelector('[x-data]')).addAction()">+ Add action</button>`;

                if (edge.trigger === 'manual') {
                    html += `<hr><label class="mb-1 d-block small">Transition-time inputs</label>`;
                    (edge.inputs || []).forEach((input, i) => html += this.renderInputRow(input, i));
                    html += `<button type="button" class="btn btn-sm btn-outline-primary" onclick="Alpine.$data(document.querySelector('[x-data]')).addInput()">+ Add input field</button>`;
                }

                html += `<div class="mt-3"><button type="button" class="btn btn-sm btn-danger" onclick="Alpine.$data(document.querySelector('[x-data]')).removeSelectedEdge()">Delete connection</button></div>`;
                return html;
            },

            // -- precondition tree (max 2 levels deep: root group -> leaves or one nested group) --
            renderPreconditionGroup(group, path, depth) {
                depth = depth || 0;
                if (! group) {
                    return `<button type="button" class="btn btn-sm btn-outline-secondary" onclick="Alpine.$data(document.querySelector('[x-data]')).initPreconditionGroup('${path}')">+ Add precondition</button>`;
                }
                let html = `<div class="wf-group-box">`;
                html += this.selectField(`${path}.op`, 'match', group.op || 'and', ['and', 'or']);
                (group.children || []).forEach((child, i) => {
                    const childPath = `${path}.children.${i}`;
                    if (child.type) {
                        html += this.renderPreconditionLeaf(child, childPath);
                    } else if (depth < 1) {
                        html += this.renderPreconditionGroup(child, childPath, depth + 1);
                    }
                    html += this.removeButton(`removeAt('${path}.children', ${i})`);
                });
                html += `<div class="mt-1">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="Alpine.$data(document.querySelector('[x-data]')).addPreconditionLeaf('${path}')">+ Condition</button>`;
                if (depth < 1) {
                    html += ` <button type="button" class="btn btn-sm btn-outline-secondary" onclick="Alpine.$data(document.querySelector('[x-data]')).addPreconditionGroup('${path}')">+ Group</button>`;
                }
                html += `</div></div>`;
                return html;
            },

            renderPreconditionLeaf(leaf, path) {
                let html = `<div class="wf-repeatable-row">`;
                html += this.selectField(`${path}.type`, 'type', leaf.type, ['field_equals', 'field_in', 'field_compare', 'model_callback']);
                if (leaf.type === 'model_callback') {
                    html += this.modelCallbackField(`${path}.method`, leaf.method || '', 'callback method');
                } else {
                    html += this.textField(`${path}.field`, 'field', leaf.field || '');
                    if (leaf.type === 'field_compare') {
                        html += this.selectField(`${path}.operator`, 'operator', leaf.operator || 'eq', ['eq', 'ne', 'lt', 'lte', 'gt', 'gte']);
                    }
                    if (leaf.type === 'field_in') {
                        html += this.textField(`${path}.values`, 'values (comma-separated)', (leaf.values || []).join(', '));
                    } else {
                        html += this.textField(`${path}.value`, 'value', leaf.value ?? '');
                    }
                }
                html += `</div>`;
                return html;
            },

            renderActionRow(action, i) {
                const path = `edge.actions.${i}`;
                const types = ['send_email', 'send_notification', 'start_timer', 'call_webhook', 'model_callback'];
                let html = `<div class="wf-repeatable-row">`;
                html += this.selectField(`${path}.type`, 'type', action.type, types);
                if (action.type === 'send_email' || action.type === 'send_notification') {
                    html += this.selectField(`${path}.to`, 'to', action.to || 'actor', ['actor', 'workflowable']);
                    html += this.textField(`${path}.${action.type === 'send_email' ? 'mailable' : 'notification'}`, action.type === 'send_email' ? 'mailable class' : 'notification class', action[action.type === 'send_email' ? 'mailable' : 'notification'] || '');
                } else if (action.type === 'start_timer') {
                    html += this.textField(`${path}.after`, 'after (e.g. 24h)', action.after || '');
                    html += this.textField(`${path}.edge`, 'edge id to fire', action.edge || '');
                } else if (action.type === 'call_webhook') {
                    html += this.textField(`${path}.url`, 'url', action.url || '');
                } else if (action.type === 'model_callback') {
                    html += this.modelCallbackField(`${path}.method`, action.method || '', 'callback method');
                }
                html += this.removeButton(`removeAt('edge.actions', ${i})`);
                html += `</div>`;
                return html;
            },

            renderInputRow(input, i) {
                const path = `edge.inputs.${i}`;
                let html = `<div class="wf-repeatable-row">`;
                html += this.textField(`${path}.name`, 'field name', input.name || '');
                html += this.selectField(`${path}.type`, 'type', input.type || 'text', ['text', 'textarea', 'number', 'select', 'date', 'checkbox']);
                html += this.selectField(`${path}.mode`, 'mode', input.mode || 'edit', ['edit', 'readonly']);
                html += `<div class="form-check">
                    <input type="checkbox" class="form-check-input" id="wf-input-required-${i}" ${input.required ? 'checked' : ''} onchange="Alpine.$data(document.querySelector('[x-data]')).setPath('${path}.required', this.checked)">
                    <label class="form-check-label small" for="wf-input-required-${i}">required</label>
                </div>`;
                html += this.textField(`${path}.store_as`, 'store as column (optional)', input.store_as || '');
                html += this.removeButton(`removeAt('edge.inputs', ${i})`);
                html += `</div>`;
                return html;
            },

            // -- single grouped ajax select2 combining roles/permissions/users --
            actorRuleField(edge) {
                const rule = edge.actor_rule || {};
                const selected = [
                    ...(rule.roles || []).map(name => `<option value="role:${name}" selected>${name}</option>`),
                    ...(rule.permissions || []).map(name => `<option value="permission:${name}" selected>${name}</option>`),
                    ...(rule.users || []).map(id => `<option value="user:${id}" selected>User #${id}</option>`),
                ].join('');

                return `<div class="form-group mb-2">
                    <select multiple class="wf-actor-select2" style="width:100%">${selected}</select>
                </div>`;
            },

            initActorSelect2() {
                const el = document.querySelector('.wf-actor-select2');
                if (! el) return;

                const $el = $(el);
                if ($el.hasClass('select2-hidden-accessible')) {
                    $el.select2('destroy');
                }

                $el.select2({
                    theme: 'bootstrap',
                    placeholder: 'Search roles, permissions, users…',
                    minimumInputLength: 0,
                    ajax: {
                        url: '{{ route('workflow.actors.search') }}',
                        dataType: 'json',
                        delay: 300,
                        data: params => ({ q: params.term }),
                        processResults: data => data,
                        cache: true,
                    },
                });

                // Update the underlying data silently on change — no rerenderInspector()
                // here, since that would destroy and recreate this very select2 mid-interaction.
                $el.on('change', () => {
                    const app = Alpine.$data(document.querySelector('[x-data]'));
                    const currentEdge = app.findEdge(app.selected.id);
                    if (! currentEdge) return;
                    const values = $el.val() || [];
                    currentEdge.actor_rule.roles = values.filter(v => v.startsWith('role:')).map(v => v.slice(5));
                    currentEdge.actor_rule.permissions = values.filter(v => v.startsWith('permission:')).map(v => v.slice(11));
                    currentEdge.actor_rule.users = values.filter(v => v.startsWith('user:')).map(v => v.slice(5));
                    // Writing to these reactive properties makes Alpine re-evaluate
                    // the x-html inspector (it tracks the read during render), which
                    // destroys this very select2 instance — reinitialize it after.
                    app.$nextTick(() => app.reinitSelect2Widgets());
                });
            },

            /** A single-select ajax picker of the target model's own public
             *  `callbackFunction*` methods — used by the actor_rule's optional
             *  model_callback, the model_callback precondition type, and the
             *  model_callback action type. Never a freeform class/method name
             *  typed into the editor: the ajax endpoint only ever reflects
             *  methods that already exist on the model (see "Rule & actor
             *  authoring UX"). */
            modelCallbackField(path, value, label) {
                const option = value ? `<option value="${value}" selected>${value}</option>` : '<option></option>';
                return `<div class="form-group mb-2">
                    <label class="mb-0 small">${label}</label>
                    <select class="wf-model-callback-select2" data-path="${path}" style="width:100%">${option}</select>
                </div>`;
            },

            initModelCallbackSelect2() {
                document.querySelectorAll('.wf-model-callback-select2').forEach(el => {
                    const $el = $(el);
                    if ($el.hasClass('select2-hidden-accessible')) {
                        $el.select2('destroy');
                    }

                    $el.select2({
                        theme: 'bootstrap',
                        placeholder: 'Search callbackFunction* methods…',
                        minimumInputLength: 0,
                        allowClear: true,
                        ajax: {
                            url: '{{ route('workflow.model-callbacks.search') }}',
                            dataType: 'json',
                            delay: 300,
                            data: params => ({ q: params.term, model: @js($definition->model) }),
                            processResults: data => data,
                            cache: true,
                        },
                    });

                    $el.on('change', () => {
                        const app = Alpine.$data(document.querySelector('[x-data]'));
                        // setPath's own rerenderInspector() already reinitializes
                        // every select2 widget afterward (see reinitSelect2Widgets).
                        app.setPath(el.dataset.path, $el.val() || '');
                    });
                });
            },

            /** All select2 widgets the inspector might currently contain —
             *  call after anything that re-renders the inspector's innerHTML
             *  (which silently destroys whatever plain DOM widgets lived in
             *  it), instead of tracking exactly which ones were present. */
            reinitSelect2Widgets() {
                this.initActorSelect2();
                this.initModelCallbackSelect2();
            },

            // -- tiny form-control helpers --
            textField(path, label, value) {
                return `<div class="form-group mb-2">
                    <label class="mb-0 small">${label}</label>
                    <input type="text" class="form-control form-control-sm" value="${(value ?? '').toString().replace(/"/g, '&quot;')}"
                        onchange="Alpine.$data(document.querySelector('[x-data]')).setPathFromInput('${path}', this.value)">
                </div>`;
            },

            selectField(path, label, value, options) {
                const opts = options.map(o => `<option value="${o}" ${o === value ? 'selected' : ''}>${o}</option>`).join('');
                return `<div class="form-group mb-2">
                    <label class="mb-0 small">${label}</label>
                    <select class="form-control form-control-sm" onchange="Alpine.$data(document.querySelector('[x-data]')).setPath('${path}', this.value)">${opts}</select>
                </div>`;
            },

            removeButton(call) {
                return `<button type="button" class="btn btn-sm btn-outline-danger" onclick="Alpine.$data(document.querySelector('[x-data]')).${call}"><i class="la la-trash"></i></button>`;
            },

            // ---------- Path-based mutation helpers, used by the inspector's inline handlers ----------

            resolveContainer(path) {
                const parts = path.split('.');
                const last = parts.pop();
                const head = parts.shift();
                // "node"/"edge" are virtual roots meaning "the currently selected
                // node/edge", not literal properties on this component.
                let obj = head === 'node' ? this.findNode(this.selected.id)
                    : head === 'edge' ? this.findEdge(this.selected.id)
                    : this[head];
                for (const part of parts) {
                    obj = /^\d+$/.test(part) ? obj[parseInt(part, 10)] : obj[part];
                }
                return { obj, last };
            },

            setPath(path, value) {
                const { obj, last } = this.resolveContainer(path);
                obj[last] = value;
                if (path === 'node.name' && this.selected?.kind === 'node') {
                    this.updateNodeLabel(this.selected.id);
                }
                if ((path === 'edge.name' || path === 'edge.button_label') && this.selected?.kind === 'edge') {
                    this.redrawOrthogonalConnections();
                }
                this.rerenderInspector();
            },

            setPathFromInput(path, value) {
                // Comma-separated list fields are stored as arrays.
                const listFields = ['roles', 'permissions', 'users', 'values'];
                if (listFields.includes(path.split('.').pop())) {
                    value = value.split(',').map(v => v.trim()).filter(v => v !== '');
                }
                this.setPath(path, value);
            },

            removeAt(path, index) {
                const { obj, last } = this.resolveContainer(path);
                obj[last].splice(index, 1);
                this.rerenderInspector();
            },

            toggleSurface(surface, checked) {
                const edge = this.findEdge(this.selected.id);
                const set = new Set(edge.surfaces || []);
                checked ? set.add(surface) : set.delete(surface);
                edge.surfaces = Array.from(set);
                this.rerenderInspector();
            },

            addAction() {
                this.findEdge(this.selected.id).actions.push({ type: 'send_notification', to: 'actor' });
                this.rerenderInspector();
            },

            addInput() {
                this.findEdge(this.selected.id).inputs.push({ name: '', type: 'text', mode: 'edit', required: false, store_as: '' });
                this.rerenderInspector();
            },

            initPreconditionGroup(path) {
                this.setPath(path, { op: 'and', children: [] });
            },

            addPreconditionGroup(path) {
                const { obj, last } = this.resolveContainer(`${path}.children`);
                obj[last].push({ op: 'and', children: [] });
                this.rerenderInspector();
            },

            addPreconditionLeaf(path) {
                const { obj, last } = this.resolveContainer(`${path}.children`);
                obj[last].push({ type: 'field_equals', field: '', value: '' });
                this.rerenderInspector();
            },

            rerenderInspector() {
                // Re-trigger the x-if/x-html blocks by reassigning `selected`.
                this.selected = { ...this.selected };
                this.$nextTick(() => this.reinitSelect2Widgets());
            },
    };
</script>
