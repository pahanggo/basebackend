<script>
    // Serializing the canvas back into the graph JSON shape the backend
    // expects, and the two ways to persist it: an AJAX "save draft" that
    // preserves in-progress editor state, and a real form-submit "publish"
    // (with a confirm dialog, since publishing is a one-way door for any
    // in-flight instance still on the previous version). Merged onto the
    // main workflowDesigner() Alpine component in edit.blade.php.
    const WF_SAVE_MIXIN = {
            buildGraphPayload() {
                return {
                    start: this.graph.start,
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

            beforeSubmit(event) {
                this.$refs.graphInput.value = JSON.stringify(this.buildGraphPayload());
            },

            /** Fires the same "draft" save the form's submit button used to,
             *  but over fetch() so the canvas/selection/zoom state survives —
             *  a full page reload here would throw away exactly the in-progress
             *  editing state a "save my progress" action is meant to protect. */
            async saveDraft() {
                if (this.savingDraft) return;
                this.savingDraft = true;
                try {
                    const response = await fetch(@js(route('workflow.designer.update', $definition)), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': document.querySelector('#workflow-designer-form input[name="_token"]').value,
                        },
                        body: JSON.stringify({ graph: JSON.stringify(this.buildGraphPayload()), action: 'draft' }),
                    });
                    const data = await response.json().catch(() => null);
                    if (! response.ok) {
                        throw new Error(data?.message || 'Save failed');
                    }
                    new Noty({ type: 'success', text: data.message }).show();
                } catch (e) {
                    new Noty({ type: 'error', text: e.message || 'Could not save the draft.' }).show();
                } finally {
                    this.savingDraft = false;
                }
            },

            /** Publish is a real form submit (not AJAX, unlike Save draft) —
             *  it navigates back to the freshly-published version, which is
             *  the point. Confirm first since it's a one-way door: once live,
             *  anyone with an available transition can act on it immediately,
             *  and the previous published version is gone from that role. */
            confirmPublish() {
                // Otherwise this button's own tooltip stays stuck on top of
                // the dialog (its hide trigger is mouseleave, which never
                // fires here since the click itself opens the dialog).
                $('.wf-float-toolbar [data-toggle="tooltip"]').tooltip('hide');
                swal({
                    title: 'Publish this version?',
                    text: "It becomes live immediately. In-flight instances already running on an earlier version are unaffected and keep using that version's graph.",
                    icon: 'warning',
                    buttons: ['Cancel', 'Publish'],
                    dangerMode: true,
                }).then((confirmed) => {
                    if (confirmed) {
                        // form.submit() (unlike a real submit-button click)
                        // never fires the "submit" event, so the @submit
                        // handler that serializes the graph into the hidden
                        // input would otherwise never run — populate it
                        // ourselves first.
                        this.beforeSubmit();
                        document.getElementById('workflow-designer-form').submit();
                    }
                });
            },
    };
</script>
