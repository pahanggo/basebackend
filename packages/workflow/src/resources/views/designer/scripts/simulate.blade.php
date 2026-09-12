<script>
    // "Test with a sample record" dry-run modal: pick a real record, walk it
    // through the graph exactly as currently drawn (unsaved edits included)
    // via WorkflowSimulateController — never touching the workflow database
    // or firing a real action. Merged onto the main workflowDesigner()
    // Alpine component in edit.blade.php.
    const WF_SIMULATE_MIXIN = {
        openSimulateModal() {
            this.simulator.recordId = null;
            this.simulator.currentNodeId = this.graph.start || (this.graph.nodes[0] && this.graph.nodes[0].id) || null;
            this.simulator.log = [];

            document.getElementById('wf-simulate-body').style.display = 'none';
            document.getElementById('wf-simulate-log-wrap').style.display = 'none';
            document.getElementById('wf-simulate-edges').innerHTML = '';

            $('#wf-simulate-modal').modal('show');
            this.$nextTick(() => this.initSimulateRecordSelect2());
        },

        initSimulateRecordSelect2() {
            const el = document.querySelector('.wf-simulate-record-select2');
            if (! el) return;

            const $el = $(el);
            if ($el.hasClass('select2-hidden-accessible')) {
                $el.select2('destroy');
            }

            $el.val(null).trigger('change');
            $el.select2({
                theme: 'bootstrap',
                dropdownParent: $('#wf-simulate-modal'),
                placeholder: 'Search for a record…',
                minimumInputLength: 0,
                ajax: {
                    url: @js(route('workflow.sample-records.search', $definition)),
                    dataType: 'json',
                    delay: 300,
                    data: params => ({ q: params.term }),
                    processResults: data => data,
                    cache: true,
                },
            });

            $el.on('change', () => {
                this.simulator.recordId = $el.val();
                if (this.simulator.recordId) this.loadSimulateNode(this.simulator.currentNodeId);
            });
        },

        /** Fetches (without firing anything) the given node's outgoing edges
         *  for the currently-picked sample record, and renders them. */
        async loadSimulateNode(nodeId) {
            this.simulator.currentNodeId = nodeId;

            const data = await this.simulatePost({ node_id: nodeId });
            if (! data) return;

            document.getElementById('wf-simulate-body').style.display = '';
            document.getElementById('wf-simulate-current-node').textContent = data.node.name || data.node.id;
            this.renderSimulateEdges(data.edges);
        },

        renderSimulateEdges(edges) {
            const container = document.getElementById('wf-simulate-edges');
            const app = "Alpine.$data(document.querySelector('[x-data]'))";

            if (! edges.length) {
                container.innerHTML = '<p class="text-muted small mb-0">No outgoing edges from this node.</p>';
                return;
            }

            container.innerHTML = edges.map(edge => {
                const badge = edge.available
                    ? '<span class="badge badge-success">available</span>'
                    : '<span class="badge badge-secondary">unavailable</span>';
                const reason = ! edge.available
                    ? `<span class="text-muted small ml-1">${edge.actor_allowed === false ? '(actor not permitted)' : '(precondition not met)'}</span>`
                    : '';
                const fireBtn = edge.available
                    ? `<button type="button" class="btn btn-sm btn-outline-primary" onclick="${app}.fireSimulateEdge('${edge.edge_id}')">Fire</button>`
                    : '';

                return `<div class="d-flex align-items-center justify-content-between wf-repeatable-row">
                    <div>${badge} <strong>${edge.label}</strong> <span class="text-muted small">(${edge.trigger})</span>${reason}</div>
                    ${fireBtn}
                </div>`;
            }).join('');
        },

        async fireSimulateEdge(edgeId) {
            const data = await this.simulatePost({ node_id: this.simulator.currentNodeId, edge_id: edgeId });
            if (! data) return;

            if (! data.ok) {
                new Noty({ type: 'error', text: data.error }).show();

                return;
            }

            this.simulator.log.push(...data.trail.map((nodeId, i) => (i === 0 ? `${edgeId} → ${nodeId}` : `↳ auto → ${nodeId}`)));
            (data.actions_preview || []).forEach(text => this.simulator.log.push(`  action: ${text}`));

            document.getElementById('wf-simulate-log-wrap').style.display = '';
            document.getElementById('wf-simulate-log').innerHTML = this.simulator.log.map(line => `<li>${line}</li>`).join('');

            // A fork can land the token on more than one node at once; a join
            // is reported as a resting point rather than guessed-completed
            // (see WorkflowSimulator) — either way, continue exploring from
            // the first resulting node, since that's the common (non-fork)
            // case, and the trail above already shows every branch reached.
            this.loadSimulateNode(data.resulting_nodes[0]);
        },

        resetSimulate() {
            this.simulator.log = [];
            document.getElementById('wf-simulate-log-wrap').style.display = 'none';
            this.loadSimulateNode(this.graph.start || (this.graph.nodes[0] && this.graph.nodes[0].id) || null);
        },

        /** Posts to WorkflowSimulateController with the graph exactly as
         *  currently drawn (unsaved edits included) — a dry run never
         *  requires saving first. Returns null (after a Noty) on failure so
         *  callers can just `if (! data) return;`. */
        async simulatePost(payload) {
            if (! this.simulator.recordId) return null;

            try {
                const response = await fetch(@js(route('workflow.designer.simulate', $definition)), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('#workflow-designer-form input[name="_token"]').value,
                    },
                    body: JSON.stringify({
                        graph: this.buildGraphPayload(),
                        workflowable_id: this.simulator.recordId,
                        ...payload,
                    }),
                });
                const data = await response.json();

                if (! response.ok) {
                    new Noty({ type: 'error', text: data.error || 'Could not run the simulation.' }).show();

                    return null;
                }

                return data;
            } catch (e) {
                new Noty({ type: 'error', text: 'Could not run the simulation — try again.' }).show();

                return null;
            }
        },
    };
</script>
