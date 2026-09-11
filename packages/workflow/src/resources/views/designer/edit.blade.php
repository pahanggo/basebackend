@extends(backpack_view('blank'))

@php
    // Seeds the field-policy modal's tableMap synchronously, so an
    // already-saved row's "table.column" display doesn't sit blank while
    // the async model-discovery fetch (which resolves related-model tables)
    // is still in flight.
    $modelTable = class_exists($definition->model) ? (new $definition->model)->getTable() : '';
@endphp

@section('after_styles')
@include('workflow::designer.partials.styles')
@endsection

@section('content')
<div x-data="workflowDesigner(@js($graph))" x-init="init()">
    <form method="POST" action="{{ route('workflow.designer.update', $definition) }}" @submit="beforeSubmit" id="workflow-designer-form">
        @csrf
        <input type="hidden" name="graph" x-ref="graphInput">
        <input type="hidden" name="action" value="publish" x-ref="actionInput">

        <div id="workflow-canvas-wrap">
            <div id="workflow-canvas">
                @include('workflow::designer.partials.toolbar')
            </div>
            @include('workflow::designer.partials.inspector-panel')
        </div>
    </form>

    @include('workflow::designer.partials.field-policy-modal')
</div>

@include('workflow::designer.partials.svg-defs')
@endsection

@section('after_scripts')
<script src="{{ asset('packages/drawflow/dist/drawflow.min.js') }}?v={{ filemtime(public_path('packages/drawflow/dist/drawflow.min.js')) }}"></script>
<script src="{{ asset('packages/select2/dist/js/select2.full.min.js') }}?v={{ filemtime(public_path('packages/select2/dist/js/select2.full.min.js')) }}"></script>

{{--
    The workflowDesigner() Alpine component is assembled from a handful of
    plain-object "mixins" (WF_CANVAS_MIXIN, WF_CONNECTIONS_MIXIN, etc.), each
    defined in its own scripts/*.blade.php file below and merged here with
    Object.assign. This package ships no build step (same as the vendored
    Drawflow/select2 assets it loads above), so splitting the component this
    way — rather than ES module imports — is what keeps each concern in its
    own maintainable file without introducing a bundler. `this` inside any
    mixin method still resolves to the final merged object at call time, so
    methods from one mixin can freely call methods/read state defined in
    another (e.g. a field-policy method calling rerenderInspector()).
--}}
@include('workflow::designer.scripts.constants')
@include('workflow::designer.scripts.canvas')
@include('workflow::designer.scripts.connections')
@include('workflow::designer.scripts.inspector')
@include('workflow::designer.scripts.field-policy')
@include('workflow::designer.scripts.save')

<script>
    function workflowDesigner(initialGraph) {
        return Object.assign(
            {
                // ---------- Core component state ----------
                // Kept on the component (rather than only as the factory's
                // closure argument) so init() — now defined in
                // scripts/canvas.blade.php's WF_CANVAS_MIXIN, outside this
                // closure — can still read the graph the server passed in.
                initialGraph: initialGraph,
                graph: { start: initialGraph.start || '', nodes: [], edges: [] },
                editor: null,
                selected: null, // {kind: 'node'|'edge', id: string}
                // Per-browser UI preference, not workflow data — kept in
                // localStorage rather than saved with the graph, and read
                // synchronously here so the panel doesn't flash visible then
                // hide on load.
                inspectorVisible: (() => {
                    try {
                        return localStorage.getItem('wf-inspector-visible') !== '0';
                    } catch (e) {
                        return true;
                    }
                })(),
                reconnecting: null, // {edgeId, end: 'from'|'to', svg}
                clipboardNode: null,
                savingDraft: false,
                // Working copy edited inside the field-policy modal — only
                // written back onto the node itself when Save is clicked, so
                // Cancel (or dismissing the modal) discards changes cleanly.
                // tableMap keys are a field's relation prefix ('' for the target
                // model's own columns, 'roles' for a field like "roles.name") —
                // used to display each row's real "table.column" path.
                fieldEditor: { nodeId: null, rows: [], tableMap: {} },
                nodeIdToDrawflowId: {},
                drawflowIdToNodeId: {},
                suppressEvents: false,
                gridSize: 20,
            },
            WF_CANVAS_MIXIN,
            WF_CONNECTIONS_MIXIN,
            WF_INSPECTOR_MIXIN,
            WF_FIELD_POLICY_MIXIN,
            WF_SAVE_MIXIN,
        );
    }
</script>
@endsection
