@extends(backpack_view('blank'))

@section('header')
    <div class="container-fluid">
        <h2>
            <span class="text-capitalize">{{ $definition->name }}</span>
            <small>Workflow designer</small>
            <small><a href="{{ url(config('backpack.base.route_prefix').'/workflows/definitions') }}" class="d-print-none font-sm"><i class="la la-angle-double-left"></i> Back to workflows</a></small>
        </h2>
    </div>
@endsection

@section('content')
@if (session('message'))
    <div class="alert alert-success">{{ session('message') }}</div>
@endif

<div x-data="workflowDesigner(@js($graph))" x-init="init()">
    <form method="POST" action="{{ route('workflow.designer.update', $definition) }}" @submit="beforeSubmit">
        @csrf
        <input type="hidden" name="graph" x-ref="graphInput">

        <div class="row">
        <div class="col-md-12 mb-3">
            <div class="card p-3">
                <label class="mb-0">Start node</label>
                <select class="form-control" style="max-width: 300px" x-model="graph.start">
                    <option value="">— choose a node —</option>
                    <template x-for="node in graph.nodes" :key="node.id">
                        <option :value="node.id" x-text="node.id"></option>
                    </template>
                </select>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card p-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h4 class="mb-0">Nodes</h4>
                    <button type="button" class="btn btn-sm btn-primary" @click="addNode()"><i class="la la-plus"></i> Add node</button>
                </div>

                <template x-for="(node, index) in graph.nodes" :key="index">
                    <div class="border rounded p-2 mb-2">
                        <div class="form-row">
                            <div class="col-4">
                                <label class="mb-0">id</label>
                                <input type="text" class="form-control form-control-sm" x-model="node.id">
                            </div>
                            <div class="col-4">
                                <label class="mb-0">type</label>
                                <select class="form-control form-control-sm" x-model="node.type">
                                    <option value="state">state</option>
                                    <option value="fork">fork</option>
                                    <option value="join">join</option>
                                </select>
                            </div>
                            <div class="col-4 text-right">
                                <label class="mb-0 d-block">&nbsp;</label>
                                <button type="button" class="btn btn-sm btn-danger" @click="removeNode(index)"><i class="la la-trash"></i></button>
                            </div>
                        </div>
                        <div class="form-group mb-0 mt-2" x-show="node.type === 'state'">
                            <label class="mb-0">field_policy (JSON array of {field, mode})</label>
                            <textarea class="form-control form-control-sm" rows="2" x-model="node.field_policy_json"></textarea>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card p-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h4 class="mb-0">Edges</h4>
                    <button type="button" class="btn btn-sm btn-primary" @click="addEdge()"><i class="la la-plus"></i> Add edge</button>
                </div>

                <template x-for="(edge, index) in graph.edges" :key="index">
                    <div class="border rounded p-2 mb-2">
                        <div class="form-row">
                            <div class="col-3">
                                <label class="mb-0">id</label>
                                <input type="text" class="form-control form-control-sm" x-model="edge.id">
                            </div>
                            <div class="col-3">
                                <label class="mb-0">from</label>
                                <input type="text" class="form-control form-control-sm" x-model="edge.from">
                            </div>
                            <div class="col-3">
                                <label class="mb-0">to</label>
                                <input type="text" class="form-control form-control-sm" x-model="edge.to">
                            </div>
                            <div class="col-3">
                                <label class="mb-0">trigger</label>
                                <select class="form-control form-control-sm" x-model="edge.trigger">
                                    <option value="manual">manual</option>
                                    <option value="automatic">automatic</option>
                                    <option value="webhook">webhook</option>
                                    <option value="timer">timer</option>
                                </select>
                            </div>
                        </div>

                        <template x-if="edge.trigger === 'manual'">
                            <div class="form-row mt-2">
                                <div class="col-6">
                                    <label class="mb-0">actor_rule.roles (comma-separated)</label>
                                    <input type="text" class="form-control form-control-sm" x-model="edge.actor_roles_csv">
                                </div>
                                <div class="col-3">
                                    <label class="mb-0">match</label>
                                    <select class="form-control form-control-sm" x-model="edge.actor_match">
                                        <option value="any">any</option>
                                        <option value="all">all</option>
                                    </select>
                                </div>
                                <div class="col-3">
                                    <div class="form-check mt-4">
                                        <input type="checkbox" class="form-check-input" x-model="edge.requires_confirmation" :id="'confirm' + index">
                                        <label class="form-check-label" :for="'confirm' + index">Requires confirmation</label>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <div class="form-group mb-0 mt-2">
                            <label class="mb-0">preconditions (JSON, optional)</label>
                            <textarea class="form-control form-control-sm" rows="2" x-model="edge.preconditions_json"></textarea>
                        </div>
                        <div class="form-group mb-0 mt-2">
                            <label class="mb-0">actions (JSON array, optional)</label>
                            <textarea class="form-control form-control-sm" rows="2" x-model="edge.actions_json"></textarea>
                        </div>
                        <div class="form-group mb-0 mt-2">
                            <label class="mb-0">inputs (JSON array of Backpack fields, optional)</label>
                            <textarea class="form-control form-control-sm" rows="2" x-model="edge.inputs_json"></textarea>
                        </div>

                        <div class="text-right mt-2">
                            <button type="button" class="btn btn-sm btn-danger" @click="removeEdge(index)"><i class="la la-trash"></i> Remove edge</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="col-md-12 mt-3">
            <button type="submit" class="btn btn-success"><i class="la la-save"></i> Save &amp; publish new version</button>
        </div>
        </div>
    </form>
</div>
@endsection

@section('after_scripts')
<script>
    function workflowDesigner(initialGraph) {
        return {
            graph: { start: '', nodes: [], edges: [] },

            init() {
                this.graph.start = initialGraph.start || '';
                this.graph.nodes = (initialGraph.nodes || []).map(node => ({
                    id: node.id,
                    type: node.type || 'state',
                    field_policy_json: JSON.stringify(node.field_policy || []),
                }));
                this.graph.edges = (initialGraph.edges || []).map(edge => ({
                    id: edge.id,
                    from: edge.from,
                    to: edge.to,
                    trigger: edge.trigger || 'manual',
                    actor_roles_csv: (edge.actor_rule && edge.actor_rule.roles || []).join(','),
                    actor_match: (edge.actor_rule && edge.actor_rule.match) || 'any',
                    requires_confirmation: !! edge.requires_confirmation,
                    preconditions_json: edge.preconditions ? JSON.stringify(edge.preconditions) : '',
                    actions_json: edge.actions ? JSON.stringify(edge.actions) : '',
                    inputs_json: edge.inputs ? JSON.stringify(edge.inputs) : '',
                }));
            },

            addNode() {
                this.graph.nodes.push({ id: 'node_' + (this.graph.nodes.length + 1), type: 'state', field_policy_json: '[]' });
            },

            removeNode(index) {
                this.graph.nodes.splice(index, 1);
            },

            addEdge() {
                this.graph.edges.push({
                    id: 'edge_' + (this.graph.edges.length + 1),
                    from: '', to: '', trigger: 'manual',
                    actor_roles_csv: '', actor_match: 'any', requires_confirmation: false,
                    preconditions_json: '', actions_json: '', inputs_json: '',
                });
            },

            removeEdge(index) {
                this.graph.edges.splice(index, 1);
            },

            /** Parses a JSON textarea, tolerating blank input as "not set". */
            parseJsonField(value, fallback) {
                if (! value || ! value.trim()) return fallback;
                try {
                    return JSON.parse(value);
                } catch (e) {
                    alert('Invalid JSON: ' + value);
                    throw e;
                }
            },

            beforeSubmit(event) {
                try {
                    const graph = {
                        start: this.graph.start,
                        nodes: this.graph.nodes.map(node => ({
                            id: node.id,
                            type: node.type,
                            ...(node.type === 'state' ? { field_policy: this.parseJsonField(node.field_policy_json, []) } : {}),
                        })),
                        edges: this.graph.edges.map(edge => {
                            const built = { id: edge.id, from: edge.from, to: edge.to, trigger: edge.trigger };

                            if (edge.trigger === 'manual') {
                                const roles = edge.actor_roles_csv.split(',').map(r => r.trim()).filter(Boolean);
                                if (roles.length) built.actor_rule = { roles: roles, match: edge.actor_match };
                                if (edge.requires_confirmation) built.requires_confirmation = true;
                            }

                            const preconditions = this.parseJsonField(edge.preconditions_json, null);
                            if (preconditions) built.preconditions = preconditions;

                            const actions = this.parseJsonField(edge.actions_json, null);
                            if (actions) built.actions = actions;

                            const inputs = this.parseJsonField(edge.inputs_json, null);
                            if (inputs) built.inputs = inputs;

                            return built;
                        }),
                    };

                    this.$refs.graphInput.value = JSON.stringify(graph);
                } catch (e) {
                    event.preventDefault();
                }
            },
        };
    }
</script>
@endsection
