<script>
    // Serializing the canvas back into the graph JSON shape the backend
    // expects, and the two ways to persist it: an AJAX "save draft", and an
    // AJAX "publish" (behind a confirm dialog, since publishing is a
    // one-way door for any in-flight instance still on the previous
    // version) — neither is a real form submit, so the designer never
    // navigates away or reloads and all its in-progress editor state
    // (canvas pan/zoom, selection, open modals) survives either action.
    // Merged onto the main workflowDesigner() Alpine component in
    // edit.blade.php.
    const WF_SAVE_MIXIN = {
            buildGraphPayload() {
                return {
                    start: this.graph.start,
                    display_name: this.graph.display_name,
                    operation_settings: this.buildOperationSettingsPayload(),
                    visibility_rules: this.buildVisibilityRulesPayload(),
                    // So a designer reopening this definition lands back where
                    // they left off instead of the default top-left/100% view.
                    view: { x: this.editor.canvas_x, y: this.editor.canvas_y, zoom: this.editor.zoom },
                    nodes: this.graph.nodes.map(n => {
                        const node = { id: n.id, name: n.name || n.id, type: n.type };
                        if (n.type === 'state' && n.field_policy && n.field_policy.length) {
                            node.field_policy = n.field_policy.filter(fp => fp.field);
                        }
                        if (n.type === 'state' && n.header_view) node.header_view = n.header_view;
                        if (n.type === 'state' && n.footer_view) node.footer_view = n.footer_view;
                        if (n.type === 'state' && n.row_actions) {
                            const rowActions = {};
                            ['show', 'update', 'delete'].forEach(op => {
                                const override = n.row_actions[op];
                                if (! override) return;
                                const entry = { enabled: override.enabled !== false };
                                const rule = override.actor_rule || {};
                                if ((rule.roles || []).length || (rule.permissions || []).length || (rule.users || []).length || rule.model_callback) {
                                    entry.actor_rule = rule;
                                }
                                rowActions[op] = entry;
                            });
                            if (Object.keys(rowActions).length) node.row_actions = rowActions;
                        }
                        // Read the live position from Drawflow itself, since that's
                        // what stays current through drags/snapping — this.graph.nodes
                        // never tracks position while editing.
                        const dfId = this.nodeIdToDrawflowId[n.id];
                        const pos = dfId != null ? this.editor.drawflow.drawflow[this.editor.module].data[dfId] : null;
                        if (pos) {
                            node.x = pos.pos_x;
                            node.y = pos.pos_y;
                        }
                        return node;
                    }),
                    edges: this.graph.edges.map(e => {
                        const built = { id: e.id, name: e.name || e.id, from: e.from, to: e.to, trigger: e.trigger };
                        if (e.trigger === 'manual') {
                            const rule = e.actor_rule || {};
                            if ((rule.roles || []).length || (rule.permissions || []).length || (rule.users || []).length || rule.model_callback) {
                                built.actor_rule = rule;
                            }
                            built.surfaces = e.surfaces && e.surfaces.length ? e.surfaces : ['record_button'];
                            if (e.button_label) built.button_label = e.button_label;
                            if (e.requires_confirmation) built.requires_confirmation = true;
                            if (e.inputs && e.inputs.length) built.inputs = e.inputs;
                        }
                        if (e.preconditions) built.preconditions = e.preconditions;
                        if (e.actions && e.actions.length) built.actions = e.actions;
                        return built;
                    }),
                };
            },

            /** Definition-level settings modal's data — only saved for an
             *  operation when it's disabled, or has an actual actor_rule set,
             *  same "don't persist an empty default" convention as an edge's
             *  actor_rule in this same file. */
            buildOperationSettingsPayload() {
                const settings = {};

                ['create', 'show', 'update', 'delete'].forEach(op => {
                    const setting = this.graph.operation_settings[op] || {};
                    const entry = { enabled: setting.enabled !== false };
                    const rule = setting.actor_rule || {};
                    if ((rule.roles || []).length || (rule.permissions || []).length || (rule.users || []).length || rule.model_callback) {
                        entry.actor_rule = rule;
                    }
                    settings[op] = entry;
                });

                return settings;
            },

            /** List-visibility rules (see Workflow\Support\WorkflowVisibilityScope)
             *  — an ORDERED array, unlike operation_settings above, so unlike
             *  every other "don't persist an empty default" spot in this
             *  file, a rule with no actor_rule at all is still saved: it's a
             *  meaningful catch-all ("match anyone"), not a blank default. */
            buildVisibilityRulesPayload() {
                return (this.graph.visibility_rules || []).map(rule => {
                    const entry = { scope: rule.scope || 'all' };
                    const actorRule = rule.actor_rule || {};
                    if ((actorRule.roles || []).length || (actorRule.permissions || []).length || (actorRule.users || []).length || actorRule.model_callback) {
                        entry.actor_rule = actorRule;
                    }
                    if (rule.scope === 'owner' && rule.owner_field) entry.owner_field = rule.owner_field;
                    if (rule.scope === 'model_callback' && rule.model_callback) entry.model_callback = rule.model_callback;
                    return entry;
                });
            },

            /** Shared by saveDraft()/confirmPublish() — posts the current
             *  graph over fetch() (never a real form submit/page navigation)
             *  so the canvas/selection/zoom/inspector state always survives,
             *  publish included. */
            async persistGraph(action) {
                const response = await fetch(@js(route('workflow.designer.update', $definition)), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('#workflow-designer-form input[name="_token"]').value,
                    },
                    body: JSON.stringify({ graph: JSON.stringify(this.buildGraphPayload()), action }),
                });
                const data = await response.json().catch(() => null);
                if (! response.ok) {
                    throw new Error(data?.message || 'Save failed');
                }
                return data;
            },

            /** Fires the "draft" save over fetch() so the canvas/selection/
             *  zoom state survives — a full page reload here would throw
             *  away exactly the in-progress editing state a "save my
             *  progress" action is meant to protect. */
            async saveDraft() {
                if (this.savingDraft) return;
                this.savingDraft = true;
                try {
                    const data = await this.persistGraph('draft');
                    this.versionCaption.hasVersion = true;
                    new Noty({ type: 'success', text: data.message }).show();
                } catch (e) {
                    new Noty({ type: 'error', text: e.message || 'Could not save the draft.' }).show();
                } finally {
                    this.savingDraft = false;
                }
            },

            /** Publish, like Save draft, is fetch()-based rather than a real
             *  form submit — the designer never navigates away or reloads,
             *  so in-progress canvas/selection/zoom/inspector state (and
             *  anything mid-edit in a modal) survives publishing the same
             *  way it already does for a draft save. Confirm first since
             *  it's still a one-way door: once live, anyone with an
             *  available transition can act on it immediately, and the
             *  previous published version is gone from that role. */
            confirmPublish() {
                if (this.savingDraft) return;

                // Otherwise this button's own tooltip stays stuck on top of
                // the dialog (its hide trigger is mouseleave, which never
                // fires here since the click itself opens the dialog).
                $('.wf-float-toolbar [data-toggle="tooltip"]').tooltip('hide');
                swal({
                    title: 'Publish this version?',
                    text: this.versioningEnabled
                        ? "It becomes live immediately. In-flight instances already running on an earlier version are unaffected and keep using that version's graph."
                        : "It becomes live immediately. Versioning is disabled for this model, so in-flight instances immediately switch to this version's graph too.",
                    icon: 'warning',
                    buttons: ['Cancel', 'Publish'],
                    dangerMode: true,
                }).then(async (confirmed) => {
                    if (! confirmed) return;

                    this.savingDraft = true;
                    try {
                        const data = await this.persistGraph('publish');
                        this.versionCaption = { hasVersion: true, number: data.version, isLive: true };
                        new Noty({ type: 'success', text: data.message }).show();
                    } catch (e) {
                        new Noty({ type: 'error', text: e.message || 'Could not publish.' }).show();
                    } finally {
                        this.savingDraft = false;
                    }
                });
            },
    };
</script>
