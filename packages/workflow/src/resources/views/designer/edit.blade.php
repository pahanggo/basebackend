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
    {{-- No longer a real submittable form — saveDraft()/confirmPublish() are
         both fetch()-based now (see scripts/save.blade.php), so the designer
         never navigates away or reloads. Kept only as the CSRF token source
         those (and the field-policy/simulate modals') fetch() calls read
         from. --}}
    <div id="workflow-designer-form">
        @csrf

        <div id="workflow-canvas-wrap">
            <div id="workflow-canvas">
                @include('workflow::designer.partials.toolbar')
            </div>
            @include('workflow::designer.partials.inspector-panel')
        </div>
    </div>

    @include('workflow::designer.partials.field-policy-modal')
    @include('workflow::designer.partials.simulate-modal')
    @include('workflow::designer.partials.settings-modal')
</div>

@include('workflow::designer.partials.svg-defs')
@endsection

@section('after_scripts')
<script src="{{ asset('packages/drawflow/dist/drawflow.js') }}?v={{ filemtime(public_path('packages/drawflow/dist/drawflow.js')) }}"></script>
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
@include('workflow::designer.scripts.simulate')
@include('workflow::designer.scripts.settings')
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
                graph: {
                    start: initialGraph.start || '',
                    // Human-facing name for the target model, shown on the
                    // show-workflow page header — see
                    // WorkflowDefinitionVersion::displayName(). Defaults to
                    // the server-humanized model class name the first time
                    // a definition is opened.
                    display_name: initialGraph.display_name || @js($modelDisplayNameDefault),
                    nodes: [],
                    edges: [],
                    // Definition-level settings (start state now lives here
                    // too, moved off the toolbar into the settings modal) —
                    // gates the target model's own Backpack create/show/update/delete
                    // operations, independent of which node a record is on
                    // ('create' has no node yet, so it's the one operation
                    // with no per-node row_actions override — just this
                    // definition-level actor_rule). See
                    // WorkflowOperation::applyWorkflowOperationAccess().
                    operation_settings: (() => {
                        const saved = initialGraph.operation_settings || {};
                        const settings = {};
                        ['create', 'show', 'update', 'delete'].forEach(op => {
                            settings[op] = {
                                enabled: saved[op]?.enabled !== false,
                                actor_rule: saved[op]?.actor_rule || null,
                            };
                        });
                        return settings;
                    })(),
                    // Ordered list-visibility rules — see
                    // Workflow\Support\WorkflowVisibilityScope. Unlike
                    // operation_settings above (always exactly one object per
                    // operation), this is a genuinely ordered array: first
                    // actor_rule match wins, so row order is meaningful and
                    // preserved exactly as saved.
                    visibility_rules: (initialGraph.visibility_rules || []).map(r => ({
                        actor_rule: r.actor_rule || null,
                        scope: r.scope || 'all',
                        owner_field: r.owner_field || '',
                        model_callback: r.model_callback || '',
                    })),
                },
                // Drives the toolbar's "Editing version N — published (currently
                // live)" / "Editing draft — not yet published" caption.
                // Seeded from the server-rendered state, then updated in place
                // after a successful saveDraft()/confirmPublish() — both are
                // AJAX now, so nothing about the page (this included) reloads
                // to pick up a fresh value from the server on its own.
                versionCaption: {
                    hasVersion: @js((bool) $latestVersion),
                    number: @js($latestVersion?->version),
                    isLive: @js($latestVersion !== null && $definition->published_version_id === $latestVersion->id),
                },
                // Whether in-flight instances stay pinned to their starting
                // version on publish, or immediately follow it instead — see
                // WorkflowDesignerController::versioningEnabledFor(). Drives
                // the publish confirm dialog's wording in WF_SAVE_MIXIN.
                versioningEnabled: @js($versioningEnabled),
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
                savingDraft: false,
                // Working copy edited inside the field-policy modal — only
                // written back onto the node itself when Save is clicked, so
                // Cancel (or dismissing the modal) discards changes cleanly.
                // tableMap keys are a field's relation prefix ('' for the target
                // model's own columns, 'roles' for a field like "roles.name") —
                // used to display each row's real "table.column" path.
                fieldEditor: { nodeId: null, rows: [], tableMap: {} },
                // "Test with a sample record" dry-run modal state — see
                // scripts/simulate.blade.php. Never saved with the graph.
                simulator: { recordId: null, actorUserId: null, currentNodeId: null, log: [] },
                nodeIdToDrawflowId: {},
                drawflowIdToNodeId: {},
                suppressEvents: false,
                gridSize: 20,
            },
            WF_CANVAS_MIXIN,
            WF_CONNECTIONS_MIXIN,
            WF_INSPECTOR_MIXIN,
            WF_FIELD_POLICY_MIXIN,
            WF_SIMULATE_MIXIN,
            WF_SETTINGS_MIXIN,
            WF_SAVE_MIXIN,
        );
    }
</script>
@endsection
