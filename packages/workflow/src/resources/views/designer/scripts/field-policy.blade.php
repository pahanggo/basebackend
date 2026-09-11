<script>
    // The node inspector's "Edit field policy" modal: loading/
    // migrating a node's saved field_policy into editable rows,
    // model-field discovery, drag-reorder, import-from-another-node, and
    // server-side validation of each row's custom_field_definition
    // (PHP array literal) on Save. Merged onto the main workflowDesigner()
    // Alpine component in edit.blade.php. Depends on WF_BACKPACK_FIELD_TYPES
    // / WF_CUSTOM_FIELD_DEFINITION_PLACEHOLDER from constants.blade.php.
    const WF_FIELD_POLICY_MIXIN = {
            fieldPolicyRowFromEntry(fp) {
                // Accepts the legacy {field, mode} shape, every previous
                // editor shape (separate options/tab/default/section
                // columns, then a JSON field_definition escape hatch), or
                // this editor's current shape (a PHP-syntax
                // custom_field_definition escape hatch). Anything from an
                // older shape folds into custom_field_definition so
                // re-opening an older saved policy doesn't silently lose it.
                const extra = {};
                if (fp.options && typeof fp.options === 'string') extra.options = this.parseLegacyOptions(fp.options);
                else if (fp.options) extra.options = fp.options;
                if (fp.tab) extra.tab = fp.tab;
                if (fp.default) extra.default = fp.default;
                if (fp.section) extra.section = fp.section;
                if (fp.field_definition) {
                    Object.assign(extra, this.tryParseJson(fp.field_definition) || {});
                }

                let customFieldDefinition = fp.custom_field_definition || '';
                if (! customFieldDefinition && Object.keys(extra).length) {
                    customFieldDefinition = this.toPhpArrayLiteral(extra);
                }

                return {
                    field: fp.field || '',
                    visible: fp.mode ? fp.mode !== 'hidden' : (fp.visible !== false),
                    label: fp.label || '',
                    type: fp.type || 'text',
                    readonly: fp.mode ? fp.mode === 'readonly' : (fp.readonly !== false),
                    custom_field_definition: customFieldDefinition,
                    // Transient UI-only state (never saved) — whether this
                    // row's Custom field definition textarea is expanded.
                    expanded: false,
                };
            },

            tryParseJson(raw) {
                try {
                    const decoded = JSON.parse(raw);
                    return (decoded && typeof decoded === 'object') ? decoded : null;
                } catch (e) {
                    return null;
                }
            },

            /** The old field-policy editor's options textarea format
             *  ("value:Label" one per line) — kept only to migrate a graph
             *  saved before the JSON, then PHP-syntax, escape hatch
             *  replaced it. */
            parseLegacyOptions(raw) {
                const options = {};
                raw.split(/\r?\n/).forEach(line => {
                    line = line.trim();
                    if (! line) return;
                    const idx = line.indexOf(':');
                    if (idx === -1) { options[line] = line; return; }
                    options[line.slice(0, idx).trim()] = line.slice(idx + 1).trim();
                });
                return options;
            },

            /** Renders a plain JS value as PHP array-literal source — used
             *  only to migrate an older saved policy's separate
             *  options/tab/default/section (or JSON field_definition) into
             *  the current PHP-syntax custom_field_definition column, so a
             *  legacy graph's data is pre-filled in the format this editor
             *  now expects rather than silently dropped. */
            toPhpArrayLiteral(value, indent) {
                indent = indent || 0;
                const pad = '    '.repeat(indent);
                const padInner = '    '.repeat(indent + 1);
                const quote = s => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;

                if (Array.isArray(value)) {
                    if (! value.length) return '[]';
                    const items = value.map(v => padInner + this.toPhpArrayLiteral(v, indent + 1)).join(',\n');
                    return `[\n${items},\n${pad}]`;
                }
                if (value && typeof value === 'object') {
                    const keys = Object.keys(value);
                    if (! keys.length) return '[]';
                    const items = keys.map(k => `${padInner}${quote(k)} => ${this.toPhpArrayLiteral(value[k], indent + 1)}`).join(',\n');
                    return `[\n${items},\n${pad}]`;
                }
                if (value === null || value === undefined) return 'null';
                if (typeof value === 'boolean') return value ? 'true' : 'false';
                if (typeof value === 'number') return String(value);
                return quote(value);
            },

            openFieldPolicyModal() {
                const node = this.findNode(this.selected.id);
                if (! node) return;

                this.fieldEditor.nodeId = node.id;
                this.fieldEditor.rows = (node.field_policy || []).map(fp => this.fieldPolicyRowFromEntry(fp));
                // Seeded synchronously so already-saved own-model rows show
                // their "table.column" path immediately, before the model
                // discovery fetch below (which fills in relation prefixes)
                // resolves.
                this.fieldEditor.tableMap = { '': @js($modelTable) };

                document.getElementById('wf-field-policy-node-name').textContent = node.name || node.id;
                this.populateImportFieldPolicySelect();
                this.renderFieldPolicyTable();
                this.loadModelFieldsForPolicy();

                $('#wf-field-policy-modal').modal('show');
            },

            /** Fills in any of the target model's (and its related models')
             *  own fields that aren't already a row — a convenience so the
             *  table starts populated instead of blank; it never overwrites
             *  a row already present (e.g. from a previously saved policy). */
            async loadModelFieldsForPolicy() {
                try {
                    const response = await fetch(`{{ route('workflow.models.fields') }}?model=${encodeURIComponent(@js($definition->model))}`, {
                        headers: { 'Accept': 'application/json' },
                    });
                    const data = await response.json();
                    const existing = new Set(this.fieldEditor.rows.map(r => r.field));

                    if (data.model_table) this.fieldEditor.tableMap[''] = data.model_table;

                    (data.fields || []).forEach(f => {
                        const lastDot = f.field.lastIndexOf('.');
                        const prefix = lastDot === -1 ? '' : f.field.slice(0, lastDot);
                        if (f.table) this.fieldEditor.tableMap[prefix] = f.table;

                        if (existing.has(f.field)) return;
                        this.fieldEditor.rows.push({
                            field: f.field, visible: true, label: f.label || '', type: f.type || 'text',
                            readonly: true, custom_field_definition: '', expanded: false,
                        });
                    });

                    this.renderFieldPolicyTable();
                } catch (e) {
                    // Discovery is a convenience — leave the table as-is
                    // (whatever the node's saved policy already had).
                }
            },

            populateImportFieldPolicySelect() {
                const select = document.getElementById('wf-field-policy-import-select');
                const others = this.graph.nodes.filter(n => n.type === 'state' && n.id !== this.fieldEditor.nodeId);
                select.innerHTML = '<option value="">— choose a node —</option>'
                    + others.map(n => `<option value="${n.id}">${n.name || n.id}</option>`).join('');
            },

            importFieldPolicy() {
                const select = document.getElementById('wf-field-policy-import-select');
                const source = this.findNode(select.value);
                if (! source) return;

                swal({
                    title: 'Import field policy?',
                    text: `This replaces every row currently in this table with the field policy from "${source.name || source.id}".`,
                    icon: 'warning',
                    buttons: ['Cancel', 'Import'],
                    dangerMode: true,
                }).then(confirmed => {
                    if (! confirmed) return;
                    this.fieldEditor.rows = (source.field_policy || []).map(fp => this.fieldPolicyRowFromEntry(fp));
                    this.renderFieldPolicyTable();
                });
            },

            setFieldPolicyRowProp(index, prop, value) {
                this.fieldEditor.rows[index][prop] = value;

                // Rows are only ever added by model discovery or Import —
                // there's no manual "Add field" any more — but a row's field
                // key can still be overridden via a "field" => 'x' entry in
                // its custom field definition, so keep it in sync when
                // present. This is a plain regex, not a PHP parse: it only
                // drives the informational Column display, so a false match
                // has no consequence.
                if (prop === 'custom_field_definition') {
                    const match = value.match(/['"]field['"]\s*=>\s*['"]([^'"]*)['"]/);
                    if (match) {
                        this.fieldEditor.rows[index].field = match[1];
                    }
                }

                // The Column display derives from the field name — re-render
                // so it (and any custom_field_definition-driven change) shows up.
                if (prop === 'type' || prop === 'field' || prop === 'custom_field_definition') this.renderFieldPolicyTable();
            },

            moveFieldPolicyRow(from, to) {
                if (to < 0 || to >= this.fieldEditor.rows.length || from === to) return;
                const [moved] = this.fieldEditor.rows.splice(from, 1);
                this.fieldEditor.rows.splice(to, 0, moved);
                this.renderFieldPolicyTable();
            },

            toggleFieldDefinitionHeight(index) {
                this.fieldEditor.rows[index].expanded = ! this.fieldEditor.rows[index].expanded;
                this.renderFieldPolicyTable();
            },

            /** Best-effort "table.column" display for a row, from the
             *  tableMap built during model discovery — purely informational,
             *  never saved with the policy. Falls back to just the column
             *  name (or the relation prefix as a stand-in table name) if
             *  discovery hasn't resolved that prefix, e.g. a manually typed
             *  field name. */
            resolveColumnPath(row) {
                const field = row.field || '';
                const lastDot = field.lastIndexOf('.');
                const prefix = lastDot === -1 ? '' : field.slice(0, lastDot);
                const column = lastDot === -1 ? field : field.slice(lastDot + 1);
                const table = this.fieldEditor.tableMap[prefix] ?? prefix;

                return table ? `${table}.${column}` : column;
            },

            renderFieldPolicyTable() {
                const tbody = document.getElementById('wf-field-policy-rows');
                if (! tbody) return;
                const app = "Alpine.$data(document.querySelector('[x-data]'))";

                tbody.innerHTML = this.fieldEditor.rows.map((row, i) => {
                    const esc = v => (v ?? '').toString().replace(/"/g, '&quot;');
                    const typeOptions = WF_BACKPACK_FIELD_TYPES.map(t => `<option value="${t}" ${t === row.type ? 'selected' : ''}>${t}</option>`).join('');

                    return `<tr draggable="true" data-index="${i}" class="wf-field-policy-row">
                        <td class="text-center wf-drag-handle" style="width: 40px; min-width: 40px;"><i class="la la-bars"></i></td>
                        <td class="text-monospace small text-muted">${esc(this.resolveColumnPath(row))}</td>
                        <td class="text-center" style="width: 40px; min-width: 40px;">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="wf-fp-visible-${i}" ${row.visible ? 'checked' : ''} onchange="${app}.setFieldPolicyRowProp(${i}, 'visible', this.checked)">
                                <label class="custom-control-label" for="wf-fp-visible-${i}"></label>
                            </div>
                        </td>
                        <td><input type="text" class="form-control form-control-sm" value="${esc(row.label)}" onchange="${app}.setFieldPolicyRowProp(${i}, 'label', this.value)"></td>
                        <td><select class="form-control form-control-sm" onchange="${app}.setFieldPolicyRowProp(${i}, 'type', this.value)">${typeOptions}</select></td>
                        <td class="text-center">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="wf-fp-readonly-${i}" ${row.readonly ? 'checked' : ''} onchange="${app}.setFieldPolicyRowProp(${i}, 'readonly', this.checked)">
                                <label class="custom-control-label" for="wf-fp-readonly-${i}"></label>
                            </div>
                        </td>
                        <td>
                            <textarea class="form-control form-control-sm text-monospace wf-custom-field-definition" rows="${row.expanded ? 10 : 1}" spellcheck="false" placeholder="${esc(WF_CUSTOM_FIELD_DEFINITION_PLACEHOLDER)}" onchange="${app}.setFieldPolicyRowProp(${i}, 'custom_field_definition', this.value)">${esc(row.custom_field_definition)}</textarea>
                            <div class="d-flex justify-content-start">
                                <i class="la ${row.expanded ? 'la-compress' : 'la-expand'} text-muted" style="cursor: pointer" title="${row.expanded ? 'Collapse' : 'Expand'}" onclick="${app}.toggleFieldDefinitionHeight(${i})"></i>
                            </div>
                        </td>
                    </tr>`;
                }).join('');

                this.bindFieldPolicyDragEvents();
            },

            /** Native HTML5 drag/drop reorder — same approach as the
             *  select_and_order field, minus Alpine templating since these
             *  rows are plain innerHTML. */
            bindFieldPolicyDragEvents() {
                const tbody = document.getElementById('wf-field-policy-rows');
                if (! tbody) return;
                let dragIndex = null;

                tbody.querySelectorAll('tr.wf-field-policy-row').forEach(row => {
                    row.addEventListener('dragstart', () => {
                        dragIndex = parseInt(row.dataset.index, 10);
                        row.classList.add('wf-dragging');
                    });
                    row.addEventListener('dragend', () => row.classList.remove('wf-dragging'));
                    row.addEventListener('dragover', e => e.preventDefault());
                    row.addEventListener('drop', e => {
                        e.preventDefault();
                        const targetIndex = parseInt(row.dataset.index, 10);
                        if (dragIndex === null) return;
                        this.moveFieldPolicyRow(dragIndex, targetIndex);
                    });
                });
            },

            /** Validates every non-empty Custom field definition server-side
             *  (it has to be — checking it's a safe literal PHP array
             *  requires the same tokenizer PHP uses) before committing the
             *  table back onto the node. Any invalid row blocks the save. */
            async saveFieldPolicyModal() {
                const node = this.findNode(this.fieldEditor.nodeId);
                if (! node) return;

                const definitions = {};
                this.fieldEditor.rows.forEach((row, i) => {
                    if (row.custom_field_definition) definitions[i] = row.custom_field_definition;
                });

                if (Object.keys(definitions).length) {
                    let data;
                    try {
                        const response = await fetch(@js(route('workflow.field-policy.validate-definitions')), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('#workflow-designer-form input[name="_token"]').value,
                            },
                            body: JSON.stringify({ definitions }),
                        });
                        data = await response.json();
                    } catch (e) {
                        new Noty({ type: 'error', text: 'Could not validate custom field definitions — try again.' }).show();
                        return;
                    }

                    if (data.errors && Object.keys(data.errors).length) {
                        this.showFieldDefinitionErrors(data.errors);
                        return;
                    }
                }

                node.field_policy = this.fieldEditor.rows
                    .filter(row => row.field)
                    .map(row => {
                        const entry = {
                            field: row.field, visible: row.visible, label: row.label, type: row.type,
                            readonly: row.readonly,
                        };
                        if (row.custom_field_definition) entry.custom_field_definition = row.custom_field_definition;
                        return entry;
                    });

                $('#wf-field-policy-modal').modal('hide');
                this.rerenderInspector();
            },

            /** Expands and red-outlines every row whose Custom field
             *  definition failed validation, with the reason as a tooltip —
             *  keeps the modal open so the designer can fix it and re-save. */
            showFieldDefinitionErrors(errors) {
                Object.keys(errors).forEach(index => {
                    if (this.fieldEditor.rows[index]) this.fieldEditor.rows[index].expanded = true;
                });
                this.renderFieldPolicyTable();

                Object.entries(errors).forEach(([index, message]) => {
                    const el = document.querySelector(`#wf-field-policy-rows tr[data-index="${index}"] .wf-custom-field-definition`);
                    if (el) {
                        el.classList.add('is-invalid');
                        el.title = message;
                    }
                });

                new Noty({ type: 'error', text: 'Fix the highlighted custom field definition(s) before saving.' }).show();
            },
    };
</script>
