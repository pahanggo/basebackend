@extends(backpack_view('blank'))

@php
    // Seeds the field-policy modal's tableMap synchronously, so an
    // already-saved row's "table.column" display doesn't sit blank while
    // the async model-discovery fetch (which resolves related-model tables)
    // is still in flight.
    $modelTable = class_exists($definition->model) ? (new $definition->model)->getTable() : '';
@endphp

@section('after_styles')
<link rel="stylesheet" href="{{ asset('packages/drawflow/dist/drawflow.min.css') }}?v={{ filemtime(public_path('packages/drawflow/dist/drawflow.min.css')) }}">
<link rel="stylesheet" href="{{ asset('packages/select2/dist/css/select2.min.css') }}?v={{ filemtime(public_path('packages/select2/dist/css/select2.min.css')) }}">
<link rel="stylesheet" href="{{ asset('packages/select2-bootstrap-theme/dist/select2-bootstrap.min.css') }}?v={{ filemtime(public_path('packages/select2-bootstrap-theme/dist/select2-bootstrap.min.css')) }}">
<style>
    #workflow-canvas-wrap { display: flex; background: #fff; }
    #workflow-canvas { flex: 1 1 auto; position: relative; background: #f7f8fa; background-image: radial-gradient(#c7cbd1 1.5px, transparent 1.5px); background-position: 10px 10px; background-size: 20px 20px; overflow: hidden; }
    .workflow-inspector { flex: 0 0 380px; border-left: 1px solid #dee2e6; background: #fff; overflow-y: auto; padding: 1rem; }
    /* Drawflow's own rule is ".drawflow .drawflow-node" (two classes) — a
       plain ".drawflow-node" selector here loses to it regardless of source
       order, since it's less specific. Matching that ancestor prefix on every
       node rule below is what actually makes these overrides take effect. */
    .drawflow .drawflow-node { background: var(--primary); color: #fff; border: 2px solid #6c757d; border-radius: 6px; padding: 10px 14px; min-width: 130px; text-align: center; font-weight: 600; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .drawflow .drawflow-node.wf-node-fork, .drawflow .drawflow-node.wf-node-join { border-radius: 50%; border-color: #6f42c1; }
    /* Overrides Drawflow's own default (.drawflow-node.selected{background:red}
       in drawflow.min.css) — background needs a value here too, not just
       border/shadow, or that red bleeds through whenever a node is selected. */
    .drawflow .drawflow-node.selected { background: #269740; border-color: #269740; box-shadow: 0 0 0 2px rgba(38,151,64,.3); color: #fff; }
    .drawflow .drawflow-node .drawflow_content_node { pointer-events: none; }
    /* Drawflow's default pointer-events:all hit-tests an open path's implied
       (fill-rule closed) interior, not just its visible stroke — for our
       angled/stapled routes that interior can span a huge invisible box far
       from the actual line, so clicking well away from a connector could
       still select it. visibleStroke limits hits to the drawn line itself. */
    .drawflow .connection .main-path { stroke: #6c757d; stroke-width: 2px; fill: none; marker-end: url(#wf-arrowhead); pointer-events: visibleStroke; }
    /* A visually invisible, much wider twin of .main-path purely for a
       forgiving click target — a 2px line is correctly hit-tested now but
       still hard to actually click. Drawflow's own click handler only checks
       that event.target.classList[0] === "main-path" (see drawflow.min.js),
       so this also carries that class and Drawflow treats a click on it as
       a click on the real connection. pointer-events:stroke (not all/visible)
       keeps hits limited to this wide stroke band, not the path's interior. */
    /* !important because Drawflow's own click handler adds ITS "selected"
       class to whichever literal path was clicked — often this one, since
       it's the widest target — which would otherwise re-trigger Drawflow's
       default ".main-path.selected{stroke:#43b993}" (visible fat green line)
       and ".main-path{marker-end:...}" (a giant arrowhead scaled up by this
       path's 14px stroke-width, since marker size defaults to strokeWidth
       units). This path must never be seen, selected or not. */
    .drawflow .connection .wf-hit-path { stroke: transparent !important; stroke-width: 14px; fill: none; marker-end: none !important; pointer-events: stroke; cursor: pointer; }
    .drawflow .connection.wf-selected .main-path:not(.wf-hit-path) { stroke: var(--primary); stroke-width: 3px; }
    .drawflow .connection .wf-edge-label-bg { fill: #f7f8fa; pointer-events: none; }
    .drawflow .connection .wf-edge-label { font-size: 11px; font-weight: 600; fill: #495057; text-anchor: middle; dominant-baseline: middle; pointer-events: none; }
    .drawflow .connection.wf-selected .wf-edge-label { fill: var(--primary); }
    /* Ports on the top/bottom faces instead of Drawflow's default left/right:
       stack the node's flex children vertically, and let .inputs/.outputs
       (naturally zero-height so they don't add flow space, per Drawflow's
       own CSS) span the full width so their dot can be centered horizontally
       and pulled onto the border via a negative/positive top offset. */
    .drawflow .drawflow-node { flex-direction: column; }
    .drawflow .drawflow-node .inputs, .drawflow .drawflow-node .outputs { width: 100%; height: 0; display: flex; justify-content: center; overflow: visible; }
    .drawflow .drawflow-node .input, .drawflow .drawflow-node .output { width: 20px; height: 20px; left: auto; right: auto; margin: 0; }
    .drawflow .drawflow-node .input { top: -25px; }
    .drawflow .drawflow-node .output { top: 7px; }

    /* Grab handles on a selected connection's two endpoints — drag either
       one onto a different node to reattach that end, without deleting and
       redrawing the whole connection. */
    .drawflow .connection .wf-reconnect-handle { fill: #fff; stroke: #7c3aed; stroke-width: 2px; cursor: grab; pointer-events: all; }
    .drawflow .connection .wf-reconnect-handle:hover { fill: #7c3aed; }
    .drawflow-node.wf-drop-target { box-shadow: 0 0 0 3px rgba(124,58,237,.5); }

    .wf-float-toolbar { position: absolute; top: 12px; left: 12px; z-index: 20; background: #fff; border-radius: 6px; box-shadow: 0 1px 6px rgba(0,0,0,.15); padding: 4px; display: flex; gap: 4px; align-items: center; }
    .wf-float-toolbar .btn { padding: .25rem .5rem; }
    .wf-minimap { position: absolute; right: 10px; bottom: 56px; width: 170px; height: 110px; background: rgba(255,255,255,.9); border: 1px solid #dee2e6; border-radius: 4px; z-index: 20; overflow: hidden; }
    .wf-minimap-inner { position: relative; width: 100%; height: 100%; }
    .wf-zoom-controls { position: absolute; right: 12px; bottom: 12px; z-index: 20; background: #fff; border-radius: 6px; box-shadow: 0 1px 6px rgba(0,0,0,.15); padding: 4px; display: flex; gap: 4px; }
    .wf-zoom-controls .btn { padding: .25rem .5rem; }

    .wf-repeatable-row { border: 1px solid #e9ecef; border-radius: 4px; padding: .5rem; margin-bottom: .5rem; background: #fafbfc; }
    .wf-group-box { border: 1px dashed #adb5bd; border-radius: 4px; padding: .5rem; margin-bottom: .5rem; }
    p.version { position: absolute;top: 60px;left: 14px;pointer-events: none;z-index: 1; }

    #wf-field-policy-table td { vertical-align: middle; min-width: 90px; }
    #wf-field-policy-table td textarea { min-width: 140px; }
    #wf-field-policy-table td .wf-custom-field-definition { min-width: 320px; font-size: 12px; }
    .wf-field-policy-row { cursor: default; }
    .wf-field-policy-row.wf-dragging { opacity: .4; }
    .wf-field-policy-row .wf-drag-handle { cursor: grab; }
</style>
@endsection

@section('content')
<div x-data="workflowDesigner(@js($graph))" x-init="init()">
    <form method="POST" action="{{ route('workflow.designer.update', $definition) }}" @submit="beforeSubmit" id="workflow-designer-form">
        @csrf
        <input type="hidden" name="graph" x-ref="graphInput">
        <input type="hidden" name="action" value="publish" x-ref="actionInput">

        <div id="workflow-canvas-wrap">
            <div id="workflow-canvas">
                <div class="wf-float-toolbar">
                    <button type="button" class="btn btn-sm btn-light" data-toggle="tooltip" title="Fullscreen" @click="toggleFullscreen()"><i class="la la-expand-arrows-alt"></i></button>
                    <a href="{{ url(config('backpack.base.route_prefix').'/workflows/definitions') }}" class="btn btn-sm btn-light" data-toggle="tooltip" title="Back to workflows"><i class="la la-times"></i></a>
                    <span class="border-left mx-1" style="height: 20px"></span>
                    <button type="button" class="btn btn-sm btn-primary" @click="addNode('state')"><i class="la la-plus"></i> State</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="addNode('fork')"><i class="la la-plus"></i> Fork</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="addNode('join')"><i class="la la-plus"></i> Join</button>
                    <span class="border-left mx-1" style="height: 20px"></span>
                    <label class="mb-0 small mr-1">Start:</label>
                    <select class="form-control form-control-sm d-inline-block" style="width: auto" x-model="graph.start">
                        <option value="">— none —</option>
                        <template x-for="node in graph.nodes" :key="node.id">
                            <option :value="node.id" x-text="node.name"></option>
                        </template>
                    </select>
                    <span class="border-left mx-1" style="height: 20px"></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle="tooltip" title="Save without affecting what's currently live, without leaving the page" @click="saveDraft()" :disabled="savingDraft">
                        <i class="la la-save"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-success" data-toggle="tooltip" title="Save and make this version live" @click="confirmPublish()"><i class="la la-cloud-upload-alt"></i></button>
                    <span class="border-left mx-1" style="height: 20px"></span>
                    <button type="button" class="btn btn-sm btn-light" data-toggle="tooltip" title="Show/hide the inspector panel" @click="toggleInspector()"><i class="la la-columns"></i></button>
                </div>

                @if ($latestVersion)
                    <p class="small text-muted mb-2 version">
                        @if ($latestVersion->version)
                            Editing version {{ $latestVersion->version }} — published{{ $definition->published_version_id === $latestVersion->id ? ' (currently live)' : '' }}
                        @else
                            Editing draft — not yet published
                        @endif
                    </p>
                @endif

                <div class="wf-minimap" id="workflow-minimap">
                    <div class="wf-minimap-inner" id="workflow-minimap-inner"></div>
                </div>

                <div class="wf-zoom-controls">
                    <button type="button" class="btn btn-sm btn-light" data-toggle="tooltip" title="Zoom in" @click="zoomIn()"><i class="la la-plus"></i></button>
                    <button type="button" class="btn btn-sm btn-light" data-toggle="tooltip" title="Zoom out" @click="zoomOut()"><i class="la la-minus"></i></button>
                    <button type="button" class="btn btn-sm btn-light" data-toggle="tooltip" title="Reset zoom &amp; pan" @click="resetView()"><i class="la la-compress"></i></button>
                </div>
            </div>
            <div class="workflow-inspector" x-show="inspectorVisible">
                <template x-if="!selected">
                    <p class="text-muted">Click a node or connection to edit it, or drag from a node's dot to another node to create a connection.</p>
                </template>

                <template x-if="selected && selected.kind === 'node'">
                    <div x-html="renderNodeInspector()"></div>
                </template>

                <template x-if="selected && selected.kind === 'edge'">
                    <div x-html="renderEdgeInspector()"></div>
                </template>
            </div>
        </div>
    </form>

    <div class="modal" id="wf-field-policy-modal" tabindex="-1" role="dialog">
        <div class="modal-xl modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Field policy &mdash; <span id="wf-field-policy-node-name"></span></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" id="wf-field-policy-table">
                        <thead>
                            <tr>
                                <th style="width: 24px"></th>
                                <th>Column</th>
                                <th style="width: 56px">Show</th>
                                <th>Label</th>
                                <th>Type</th>
                                <th style="width: 70px">Readonly</th>
                                <th style="width: 600px">Custom field definition</th>
                            </tr>
                        </thead>
                        <tbody id="wf-field-policy-rows"></tbody>
                    </table>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <div class="form-inline mr-3">
                        <label class="mr-2 mb-0">Import from</label>
                        <select class="form-control mr-2" id="wf-field-policy-import-select" style="width: auto"></select>
                        <button type="button" class="btn btn-outline-secondary" onclick="Alpine.$data(document.querySelector('[x-data]')).importFieldPolicy()">Import</button>
                    </div>
                    <div>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="Alpine.$data(document.querySelector('[x-data]')).saveFieldPolicyModal()">Save</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<svg width="0" height="0" style="position: absolute">
    <defs>
        <marker id="wf-arrowhead" markerWidth="10" markerHeight="6" refX="9" refY="3" orient="auto">
            <path d="M0,0 L10,3 L0,6 L2.5,3 Z" fill="#6c757d"></path>
        </marker>
    </defs>
</svg>
@endsection

@section('after_scripts')
<script src="{{ asset('packages/drawflow/dist/drawflow.min.js') }}?v={{ filemtime(public_path('packages/drawflow/dist/drawflow.min.js')) }}"></script>
<script src="{{ asset('packages/select2/dist/js/select2.full.min.js') }}?v={{ filemtime(public_path('packages/select2/dist/js/select2.full.min.js')) }}"></script>
<script>
    // Every field type this app's own crud/fields views registers (see
    // resources/views/crud/fields/*.blade.php) — offered as-is in the field
    // policy editor's "type" column rather than hand-maintaining a second,
    // narrower list.
    const WF_BACKPACK_FIELD_TYPES = [
        'address_google', 'ajax_multi_upload', 'ajax_upload', 'base64_image', 'boolean', 'browse',
        'browse_multiple', 'checkbox', 'checklist', 'checklist_dependency', 'ckeditor', 'color',
        'color_picker', 'custom_html', 'date', 'date_only', 'date_picker', 'date_range', 'datetime',
        'datetime_picker', 'dependent_select', 'easymde', 'email', 'enum', 'hidden', 'icon_picker',
        'identity', 'image', 'latlng_picker', 'model_picker', 'money', 'month', 'number',
        'page_or_link', 'password', 'phone', 'radio', 'range', 'relationship', 'repeatable', 'select',
        'select2', 'select2_from_ajax', 'select2_from_ajax_multiple', 'select2_from_array',
        'select2_grouped', 'select2_multiple', 'select2_nested', 'select_and_order',
        'select_from_array', 'select_grouped', 'select_multiple', 'simplemde', 'slug', 'summernote',
        'switch', 'table', 'tags', 'text', 'textarea', 'time', 'time_range', 'tinymce', 'upload',
        'upload_multiple', 'url', 'video', 'view', 'week', 'wysiwyg',
    ];

    // Shown as the "Custom field definition" column's placeholder — written
    // as a literal PHP array, matching how a developer would write this
    // same config by hand in a CrudController. Merged onto the built
    // Backpack field server-side via a token-whitelisted, safe-eval-only
    // parser (see FieldPolicyResolver) — never raw PHP execution.
    const WF_CUSTOM_FIELD_DEFINITION_PLACEHOLDER = `[
    'options' => ['a' => 'A', 'b' => 'B'],
    'tab' => 'Details',
]`;

    function workflowDesigner(initialGraph) {
        return {
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

            init() {
                // Guard against double-initialization (e.g. Alpine re-processing
                // the page) leaving two overlapping Drawflow instances behind.
                var canvasEl = document.getElementById('workflow-canvas');
                if (canvasEl.dataset.wfInitialized) {
                    return;
                }
                canvasEl.dataset.wfInitialized = '1';

                this.graph.nodes = (initialGraph.nodes || []).map(n => ({
                    id: n.id, name: n.name || n.id, type: n.type || 'state', field_policy: n.field_policy || [],
                    header_view: n.header_view || '', footer_view: n.footer_view || '',
                    x: n.x, y: n.y,
                }));
                this.graph.edges = (initialGraph.edges || []).map(e => this.normalizeEdge(e));

                this.editor = new Drawflow(canvasEl);
                this.editor.reroute = false;
                this.editor.curvature = 0;
                this.editor.start();

                this.graph.nodes.forEach((node, i) => {
                    // Use the saved position if this node has one; otherwise
                    // fall back to the auto-grid layout (e.g. a brand new node).
                    const x = typeof node.x === 'number' ? node.x : this.snap(120 + (i % 4) * 240);
                    const y = typeof node.y === 'number' ? node.y : this.snap(100 + Math.floor(i / 4) * 160);
                    this.drawNode(node, x, y);
                });
                this.$nextTick(() => {
                    this.drawEdges();
                    this.redrawOrthogonalConnections();
                    this.updateMinimap();
                    this.layoutFullBleed();
                    this.restoreView(initialGraph.view);
                    // Alpine bound the "Start node" <select>'s initial value
                    // before its x-for had any <option>s to match against
                    // (graph.nodes started empty), so the browser silently
                    // fell back to "— none —" even though graph.start itself
                    // was already correct — reconcile the visible selection
                    // now that the options actually exist.
                    const startSelect = document.querySelector('.wf-float-toolbar select');
                    if (startSelect) startSelect.value = this.graph.start;
                });

                this.editor.on('connectionCreated', (c) => { this.onConnectionCreated(c); this.redrawOrthogonalConnections(); });
                this.editor.on('connectionRemoved', (c) => this.onConnectionRemoved(c));
                this.editor.on('nodeSelected', (id) => this.onNodeSelected(id));
                this.editor.on('nodeMoved', (id) => this.onNodeMoved(id));
                this.editor.on('connectionSelected', (c) => this.onConnectionSelected(c));
                this.editor.on('zoom', (z) => this.onZoom(z));
                this.editor.on('translate', (pos) => this.onTranslate(pos));

                // Redraw connections live while dragging a node, and keep them
                // orthogonal even mid-drag rather than snapping only at drop.
                canvasEl.addEventListener('mousemove', (e) => {
                    if (this.editor.drag) this.redrawOrthogonalConnections();
                    if (this.editor.connection) this.updateDraggingConnectionPath(e);
                    if (this.reconnecting) this.updateReconnectPath(e);
                });

                // Grabbing a selected connection's endpoint handle starts a
                // reconnect drag instead of Drawflow's own node/connection
                // logic — capture phase + stopPropagation so Drawflow's own
                // mousedown handler never sees this click at all.
                canvasEl.addEventListener('mousedown', (e) => {
                    if (e.target.classList && e.target.classList.contains('wf-reconnect-handle')) {
                        e.preventDefault();
                        e.stopPropagation();
                        this.startReconnect(e.target);
                    }
                }, true);
                window.addEventListener('mouseup', (e) => this.finishReconnect(e));

                // Drawflow's own right-click handler (bound inside
                // editor.start(), so it's already attached by the time we
                // get here) shows a small "×" delete-this overlay on
                // whatever was under the cursor — redundant with, and less
                // discoverable than, this inspector's own "Delete node"/
                // "Delete connection" button, and easy to trigger by
                // accident. Intercepting on the capture phase from the
                // wrapping element (rather than canvasEl itself, where
                // Drawflow's listener would still run first since it was
                // registered earlier) stops the event before it ever
                // reaches Drawflow's handler.
                document.getElementById('workflow-canvas-wrap').addEventListener('contextmenu', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                }, true);

                window.addEventListener('resize', () => this.layoutFullBleed());
                window.addEventListener('keydown', (e) => this.handleKeydown(e));

                // Toggling the admin sidebar changes .main's offset via a CSS
                // class on <body> (sidebar-hidden/sidebar-lg-show/etc, per
                // CoreUI) — that never fires a window "resize" event since
                // the viewport itself doesn't change, so layoutFullBleed()
                // would otherwise never re-run and the canvas would keep
                // whatever width it had when the sidebar was last in a
                // different state. Re-measure once immediately (for a snappy
                // response) and again after the sidebar's own CSS transition
                // finishes, so the final position is exact either way.
                new MutationObserver(() => {
                    this.layoutFullBleed();
                    setTimeout(() => this.layoutFullBleed(), 300);
                }).observe(document.body, { attributes: true, attributeFilter: ['class'] });

                // The toolbar itself is static markup (unlike the inspector
                // panel, which re-renders via x-html), so a single init here
                // is enough — no need to reinitialize on every redraw the way
                // the select2 widgets do. Same convention as the rest of the
                // app (e.g. crud/buttons/delete.blade.php): data-toggle
                // ="tooltip" + title, not the raw browser tooltip.
                $('.wf-float-toolbar [data-toggle="tooltip"], .wf-zoom-controls [data-toggle="tooltip"]').tooltip();
            },

            /** Ctrl/Cmd+C copies the selected state/fork/join node; Ctrl/Cmd+V
             *  drops a clone of the last-copied node next to its source.
             *  Ignored while typing in an inspector field so it doesn't
             *  hijack normal copy/paste there. */
            handleKeydown(e) {
                if (! (e.ctrlKey || e.metaKey)) return;
                const key = e.key.toLowerCase();

                // Save works from anywhere on the page, including while typing
                // in an inspector field — it should also suppress the browser's
                // own "Save page" dialog rather than let that fire alongside it.
                if (key === 's') {
                    e.preventDefault();
                    this.saveDraft();
                    return;
                }

                const tag = document.activeElement ? document.activeElement.tagName : '';
                if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || (document.activeElement && document.activeElement.isContentEditable)) return;

                if (key === 'c' && this.selected && this.selected.kind === 'node') {
                    e.preventDefault();
                    this.copySelectedNode();
                } else if (key === 'v' && this.clipboardNode) {
                    e.preventDefault();
                    this.pasteClonedNode();
                }
            },

            copySelectedNode() {
                const node = this.findNode(this.selected.id);
                if (node) this.clipboardNode = JSON.parse(JSON.stringify(node));
            },

            pasteClonedNode() {
                const source = this.clipboardNode;
                if (! source) return;

                const id = this.generateNodeId(source.type);
                const node = {
                    id,
                    name: source.name + ' copy',
                    type: source.type,
                    field_policy: JSON.parse(JSON.stringify(source.field_policy || [])),
                    header_view: source.header_view || '',
                    footer_view: source.footer_view || '',
                };
                this.graph.nodes.push(node);

                const sourceDfId = this.nodeIdToDrawflowId[source.id];
                const sourcePos = sourceDfId != null ? this.editor.drawflow.drawflow[this.editor.module].data[sourceDfId] : null;
                const x = this.snap((sourcePos ? sourcePos.pos_x : 120) + 220);
                const y = this.snap(sourcePos ? sourcePos.pos_y : 100);
                this.drawNode(node, x, y);
                this.updateMinimap();
                this.selectNodeVisually(id);
            },

            /** Marks a node selected both in our Alpine state (drives the
             *  inspector) and in the DOM (Drawflow's own "selected" styling),
             *  for selections we trigger ourselves rather than via a real
             *  click through Drawflow's own handler. */
            selectNodeVisually(nodeId) {
                document.querySelectorAll('.drawflow-node.selected').forEach(el => el.classList.remove('selected'));
                const dfId = this.nodeIdToDrawflowId[nodeId];
                if (dfId != null) {
                    const el = document.getElementById('node-' + dfId);
                    if (el) el.classList.add('selected');
                }
                this.selected = { kind: 'node', id: nodeId };
                this.redrawOrthogonalConnections();
            },

            snap(value) {
                return Math.round(value / this.gridSize) * this.gridSize;
            },

            /** Makes the canvas fill the viewport below/right of the admin
             *  chrome (the fixed header + sidebar). Measures `.main` — a
             *  still-in-flow ancestor, never `#workflow-canvas-wrap` itself
             *  (which this function fixed-positions) — so the canvas keeps
             *  tracking the sidebar's current width: CoreUI already shifts
             *  `.main`'s own offset whenever the admin sidebar is toggled,
             *  we just need to keep re-reading it instead of caching it once. */
            layoutFullBleed() {
                const wrap = document.getElementById('workflow-canvas-wrap');
                const reference = wrap.closest('.main') || wrap.parentElement;
                const rect = reference.getBoundingClientRect();
                wrap.style.position = 'fixed';
                wrap.style.top = rect.top + 'px';
                wrap.style.left = rect.left + 'px';
                wrap.style.right = '0px';
                wrap.style.bottom = '0px';
                wrap.style.zIndex = '1000';
            },

            toggleFullscreen() {
                const el = document.getElementById('workflow-canvas-wrap');
                if (! document.fullscreenElement) {
                    el.requestFullscreen();
                } else {
                    document.exitFullscreen();
                }
            },

            toggleInspector() {
                this.inspectorVisible = ! this.inspectorVisible;
                try {
                    localStorage.setItem('wf-inspector-visible', this.inspectorVisible ? '1' : '0');
                } catch (e) {
                    // Private-browsing/storage-disabled edge case — the
                    // toggle still works for this session, it just won't
                    // be remembered next time.
                }
            },

            /** Keeps the dot-grid background in step with Drawflow's zoom, so it
             *  still lines up with the (unscaled-coordinate) node grid. */
            onZoom(zoom) {
                const canvasEl = document.getElementById('workflow-canvas');
                const size = this.gridSize * zoom;
                canvasEl.style.backgroundSize = size + 'px ' + size + 'px';
                // Scale the dot radius too, not just the grid spacing, so dots
                // stay visually proportional at any zoom level.
                const dotRadius = 1.5 * zoom;
                canvasEl.style.backgroundImage = `radial-gradient(#c7cbd1 ${dotRadius}px, transparent ${dotRadius}px)`;
            },

            /** Keeps the dot-grid in sync while panning the canvas, since the
             *  background lives on the (untransformed) outer container, not
             *  on precanvas which is what Drawflow actually pans/zooms. */
            onTranslate(pos) {
                document.getElementById('workflow-canvas').style.backgroundPosition = pos.x + 'px ' + pos.y + 'px';
            },

            zoomIn() { this.editor.zoom_in(); },
            zoomOut() { this.editor.zoom_out(); },

            resetView() {
                this.editor.zoom = 1;
                this.editor.zoom_last_value = 1;
                this.editor.canvas_x = 0;
                this.editor.canvas_y = 0;
                this.editor.precanvas.style.transform = 'translate(0px, 0px) scale(1)';
                this.onZoom(1);
                this.onTranslate({ x: 0, y: 0 });
            },

            /** Applies a previously-saved pan/zoom, or leaves the default
             *  (100%, top-left) view when this definition has never saved one. */
            restoreView(view) {
                if (! view || typeof view.zoom !== 'number') return;
                this.editor.zoom = view.zoom;
                this.editor.zoom_last_value = view.zoom;
                this.editor.canvas_x = view.x || 0;
                this.editor.canvas_y = view.y || 0;
                this.editor.precanvas.style.transform = `translate(${this.editor.canvas_x}px, ${this.editor.canvas_y}px) scale(${this.editor.zoom})`;
                this.onZoom(this.editor.zoom);
                this.onTranslate({ x: this.editor.canvas_x, y: this.editor.canvas_y });
            },

            normalizeEdge(e) {
                return {
                    id: e.id, name: e.name || e.id, from: e.from, to: e.to, trigger: e.trigger || 'manual',
                    actor_rule: { roles: [], permissions: [], users: [], model_callback: '', match: 'any', ...(e.actor_rule || {}) },
                    surfaces: e.surfaces || ['record_button'],
                    button_label: e.button_label || '',
                    requires_confirmation: !! e.requires_confirmation,
                    preconditions: e.preconditions || null,
                    actions: e.actions || [],
                    inputs: e.inputs || [],
                };
            },

            // ---------- Canvas <-> graph sync ----------

            drawNode(node, x, y) {
                const html = `<div>${node.name}</div>`;
                const cssClass = node.type === 'fork' ? 'wf-node-fork' : (node.type === 'join' ? 'wf-node-join' : '');
                const dfId = this.editor.addNode(node.id, 1, 1, x, y, cssClass, { nodeId: node.id }, html, false);
                this.nodeIdToDrawflowId[node.id] = dfId;
                this.drawflowIdToNodeId[dfId] = node.id;
            },

            /** Updates a node's on-canvas label after its name is edited in the inspector. */
            updateNodeLabel(nodeId) {
                const node = this.findNode(nodeId);
                const dfId = this.nodeIdToDrawflowId[nodeId];
                if (! node || dfId == null) return;
                const contentEl = document.querySelector('#node-' + dfId + ' .drawflow_content_node');
                if (contentEl) contentEl.textContent = node.name;
            },

            drawEdges() {
                this.suppressEvents = true;
                this.graph.edges.forEach(edge => {
                    const fromDf = this.nodeIdToDrawflowId[edge.from];
                    const toDf = this.nodeIdToDrawflowId[edge.to];
                    if (fromDf && toDf) {
                        try { this.editor.addConnection(fromDf, toDf, 'output_1', 'input_1'); } catch (e) {}
                    }
                });
                this.suppressEvents = false;
            },

            /** Finds the next free `type_N` id — a plain counter can collide
             *  once nodes have been deleted or cloned out of sequence. */
            generateNodeId(type) {
                let n = this.graph.nodes.length + 1;
                let id = type + '_' + n;
                while (this.findNode(id)) {
                    n++;
                    id = type + '_' + n;
                }
                return id;
            },

            addNode(type) {
                const id = this.generateNodeId(type);
                const node = { id, name: id, type, field_policy: [] };
                this.graph.nodes.push(node);
                const count = this.graph.nodes.length;
                this.drawNode(node, this.snap(120 + ((count - 1) % 4) * 240), this.snap(100 + Math.floor((count - 1) / 4) * 160));
                this.updateMinimap();
            },

            onConnectionCreated(c) {
                if (this.suppressEvents) return;
                const from = this.drawflowIdToNodeId[c.output_id];
                const to = this.drawflowIdToNodeId[c.input_id];
                if (! from || ! to) return;
                const id = 'edge_' + (this.graph.edges.length + 1) + '_' + Date.now().toString(36);
                this.graph.edges.push(this.normalizeEdge({ id, from, to }));
            },

            onConnectionRemoved(c) {
                if (this.suppressEvents) return;
                const from = this.drawflowIdToNodeId[c.output_id];
                const to = this.drawflowIdToNodeId[c.input_id];
                const idx = this.graph.edges.findIndex(e => e.from === from && e.to === to);
                if (idx !== -1) this.graph.edges.splice(idx, 1);
                if (this.selected && this.selected.kind === 'edge') this.selected = null;
                this.redrawOrthogonalConnections();
            },

            onNodeSelected(dfId) {
                const nodeId = this.drawflowIdToNodeId[dfId];
                if (nodeId) this.selected = { kind: 'node', id: nodeId };
                this.redrawOrthogonalConnections();
            },

            onNodeMoved(dfId) {
                const nodeData = this.editor.drawflow.drawflow[this.editor.module].data[dfId];
                if (! nodeData) return;
                nodeData.pos_x = this.snap(nodeData.pos_x);
                nodeData.pos_y = this.snap(nodeData.pos_y);
                const el = document.getElementById('node-' + dfId);
                if (el) {
                    el.style.left = nodeData.pos_x + 'px';
                    el.style.top = nodeData.pos_y + 'px';
                    this.editor.updateConnectionNodes('node-' + dfId);
                }
                this.redrawOrthogonalConnections();
                this.updateMinimap();
            },

            onConnectionSelected(c) {
                const from = this.drawflowIdToNodeId[c.output_id];
                const to = this.drawflowIdToNodeId[c.input_id];
                const edge = this.graph.edges.find(e => e.from === from && e.to === to);
                if (edge) {
                    this.selected = { kind: 'edge', id: edge.id };
                    this.redrawOrthogonalConnections();
                    this.$nextTick(() => this.reinitSelect2Widgets());
                }
            },

            selectEdgeById(id) {
                this.selected = { kind: 'edge', id };
                this.redrawOrthogonalConnections();
                this.$nextTick(() => this.reinitSelect2Widgets());
            },

            findNode(id) { return this.graph.nodes.find(n => n.id === id); },
            findEdge(id) { return this.graph.edges.find(e => e.id === id); },

            removeSelectedNode() {
                const node = this.findNode(this.selected.id);
                if (! node) return;
                const dfId = this.nodeIdToDrawflowId[node.id];
                if (dfId) this.editor.removeNodeId('node-' + dfId);
                this.graph.nodes = this.graph.nodes.filter(n => n.id !== node.id);
                this.graph.edges = this.graph.edges.filter(e => e.from !== node.id && e.to !== node.id);
                this.selected = null;
                this.redrawOrthogonalConnections();
                this.updateMinimap();
            },

            removeSelectedEdge() {
                const edge = this.findEdge(this.selected.id);
                if (! edge) return;
                this.graph.edges = this.graph.edges.filter(e => e.id !== edge.id);
                this.selected = null;
                this.redrawOrthogonalConnections();
                // Also remove the visual connection, if it still exists.
                const fromDf = this.nodeIdToDrawflowId[edge.from];
                const toDf = this.nodeIdToDrawflowId[edge.to];
                if (fromDf && toDf) {
                    this.suppressEvents = true;
                    try { this.editor.removeSingleConnection(fromDf, toDf, 'output_1', 'input_1'); } catch (e) {}
                    this.suppressEvents = false;
                }
            },

            // ---------- Orthogonal (Manhattan-style) connector rendering ----------
            // Drawflow draws smooth bezier curves by default; this overrides each
            // connection's path with a right-angle elbow so it matches box-style
            // flow-chart UIs (with arrowheads via the shared <marker> defined in
            // the Blade template).

            /** Shared by both finished connections and the in-progress one being
             *  dragged out, so both use identical routing rules. */
            /** Builds the SVG path 'd' from an ordered list of {x,y} points. */
            pointsToPath(points) {
                return 'M ' + points.map(p => `${p.x} ${p.y}`).join(' L ');
            },

            /** Axis-aligned segment/rectangle overlap test — every segment we
             *  ever draw is purely vertical or purely horizontal, so this
             *  never needs general line-rect intersection math. */
            segmentHitsRect(ax, ay, bx, by, rect) {
                if (ax === bx) {
                    const lo = Math.min(ay, by), hi = Math.max(ay, by);
                    return ax >= rect.left && ax <= rect.right && hi >= rect.top && lo <= rect.bottom;
                }
                if (ay === by) {
                    const lo = Math.min(ax, bx), hi = Math.max(ax, bx);
                    return ay >= rect.top && ay <= rect.bottom && hi >= rect.left && lo <= rect.right;
                }
                return false;
            },

            pathHitsObstacle(points, obstacles) {
                for (let i = 0; i < points.length - 1; i++) {
                    for (const rect of obstacles) {
                        if (this.segmentHitsRect(points[i].x, points[i].y, points[i + 1].x, points[i + 1].y, rect)) {
                            return true;
                        }
                    }
                }
                return false;
            },

            /** Bounding boxes (with a small margin) of every node except the
             *  ones given — i.e. every node a connector could accidentally
             *  cut through besides the two it's actually meant to touch. */
            obstacleRectsExcluding(excludeIds) {
                const data = this.editor.drawflow.drawflow[this.editor.module].data;
                const margin = 10;
                const rects = [];
                Object.keys(data).forEach(dfId => {
                    const nodeId = this.drawflowIdToNodeId[dfId];
                    if (! nodeId || excludeIds.includes(nodeId)) return;
                    const el = document.getElementById('node-' + dfId);
                    const n = data[dfId];
                    if (! el || ! n) return;
                    rects.push({
                        left: n.pos_x - margin,
                        right: n.pos_x + el.offsetWidth + margin,
                        top: n.pos_y - margin,
                        bottom: n.pos_y + el.offsetHeight + margin,
                    });
                });
                return rects;
            },

            orthogonalPath(x1, y1, x2, y2, lane = 0, obstacles = []) {
                // Connectors always leave/enter perpendicular to the node edge
                // — vertically here, since ports sit on the top/bottom faces
                // (output at the bottom, input at the top). The simple 2-bend
                // "jog at the midpoint" path only looks right when that gives
                // both the exit and entrance a real stub to travel along
                // before turning; below a minimum length (or when the target
                // sits above the source, making the stub negative) it
                // collapses into an ugly near-instant turn right at the port
                // — so fall back to routing around with fixed-length stubs instead.
                const minStub = 10;
                const midY = (y1 + y2) / 2;
                const exitStub = midY - y1;
                const entranceStub = y2 - midY;

                if (exitStub >= minStub && entranceStub >= minStub) {
                    const straight = [{ x: x1, y: y1 }, { x: x1, y: midY }, { x: x2, y: midY }, { x: x2, y: y2 }];
                    if (! this.pathHitsObstacle(straight, obstacles)) {
                        return this.pointsToPath(straight);
                    }
                    // The direct jog would cut through some other node that
                    // just happens to sit between source and target — fall
                    // through to the routed-around shape below instead.
                }

                const stub = 24;
                // When both ports sit on (or near) the same column — the
                // common case for a "return"/backward edge between two boxes
                // stacked vertically — the midpoint-average jog degenerates
                // to a near-zero horizontal offset, so the "vertical" leg
                // cuts straight through both node bodies instead of routing
                // around them. Detour to the side of the column by a fixed
                // clearance instead whenever the columns are too close to
                // leave real room to route between.
                // `lane` (assigned by computeDetourLanes, for edges whose
                // detour ranges overlap on screen) pushes this one further
                // over so two such edges never trace the exact same pixels —
                // otherwise clicking one could hit whichever happens to be on
                // top in the DOM, selecting a completely unrelated edge.
                const columnClearance = 100;
                const laneStep = 24;
                const baseMidX = Math.abs(x1 - x2) < columnClearance
                    ? Math.max(x1, x2) + columnClearance
                    : (x1 + x2) / 2;
                const p1y = y1 + stub;
                const p2y = y2 - stub;

                // If a plain node (not involved in this edge) still sits in
                // the way of that detour column, keep pushing further over —
                // an unrelated third node can easily land between two edge
                // endpoints in a busy diagram, and a fixed clearance alone
                // only ever accounts for the source/target pair.
                let points;
                for (let attempt = 0; attempt < 8; attempt++) {
                    const midX = baseMidX + lane * laneStep + attempt * laneStep;
                    points = [
                        { x: x1, y: y1 }, { x: x1, y: p1y }, { x: midX, y: p1y },
                        { x: midX, y: p2y }, { x: x2, y: p2y }, { x: x2, y: y2 },
                    ];
                    if (! this.pathHitsObstacle(points, obstacles)) break;
                }
                return this.pointsToPath(points);
            },

            /** Assigns each edge that needs the routed (non-diagonal) fallback
             *  path a "lane" — greedy interval-overlap stacking, like calendar
             *  event layout — so two detouring edges whose vertical ranges
             *  overlap never end up tracing the exact same line on screen. */
            computeDetourLanes() {
                const data = this.editor.drawflow.drawflow[this.editor.module].data;
                const minStub = 10;
                const stub = 24;
                const items = [];

                this.graph.edges.forEach(edge => {
                    const fromDf = this.nodeIdToDrawflowId[edge.from];
                    const toDf = this.nodeIdToDrawflowId[edge.to];
                    const outNode = data[fromDf], inNode = data[toDf];
                    const outEl = document.getElementById('node-' + fromDf);
                    const inEl = document.getElementById('node-' + toDf);
                    if (! outNode || ! inNode || ! outEl || ! inEl) return;

                    const y1 = outNode.pos_y + outEl.offsetHeight;
                    const y2 = inNode.pos_y;
                    const midY = (y1 + y2) / 2;
                    if (midY - y1 >= minStub && y2 - midY >= minStub) return; // 2-bend path, no detour

                    const p1y = y1 + stub, p2y = y2 - stub;
                    items.push({ id: edge.id, min: Math.min(p1y, p2y), max: Math.max(p1y, p2y) });
                });

                items.sort((a, b) => a.min - b.min);
                const laneEnds = [];
                const lanes = {};
                items.forEach(item => {
                    let lane = laneEnds.findIndex(end => end <= item.min);
                    if (lane === -1) { lane = laneEnds.length; laneEnds.push(item.max); }
                    else { laneEnds[lane] = item.max; }
                    lanes[item.id] = lane;
                });

                this.edgeDetourLane = lanes;
            },

            redrawOrthogonalConnections() {
                this.computeDetourLanes();
                // Handles are re-created below only for the currently selected
                // edge — drop stale ones from whatever was selected before.
                document.querySelectorAll('.wf-reconnect-handle').forEach(h => h.remove());
                const data = this.editor.drawflow.drawflow[this.editor.module].data;
                document.querySelectorAll('#workflow-canvas .connection').forEach(svg => {
                    const inClass = Array.from(svg.classList).find(c => c.startsWith('node_in_'));
                    const outClass = Array.from(svg.classList).find(c => c.startsWith('node_out_'));
                    if (! inClass || ! outClass) return;

                    const inId = inClass.replace('node_in_node-', '');
                    const outId = outClass.replace('node_out_node-', '');
                    const outNode = data[outId], inNode = data[inId];
                    const outEl = document.getElementById('node-' + outId);
                    const inEl = document.getElementById('node-' + inId);
                    if (! outNode || ! inNode || ! outEl || ! inEl) return;

                    const x1 = outNode.pos_x + outEl.offsetWidth / 2, y1 = outNode.pos_y + outEl.offsetHeight;
                    const x2 = inNode.pos_x + inEl.offsetWidth / 2, y2 = inNode.pos_y;

                    const fromNodeId = this.drawflowIdToNodeId[outId];
                    const toNodeId = this.drawflowIdToNodeId[inId];
                    const edge = this.graph.edges.find(e => e.from === fromNodeId && e.to === toNodeId);
                    const lane = edge ? (this.edgeDetourLane[edge.id] || 0) : 0;
                    const obstacles = this.obstacleRectsExcluding([fromNodeId, toNodeId]);

                    const path = svg.querySelector('.main-path');
                    if (path) {
                        const d = this.orthogonalPath(x1, y1, x2, y2, lane, obstacles);
                        path.setAttribute('d', d);
                        this.ensureHitPath(svg, d);
                    }

                    if (edge) {
                        this.updateConnectionLabel(svg, edge);
                        if (path && this.selected?.kind === 'edge' && this.selected.id === edge.id) {
                            // Offset a little way along the path rather than
                            // sitting exactly on the endpoint — Drawflow's own
                            // port dot lives right there with a higher
                            // z-index, and would swallow the click before it
                            // ever reached our handle.
                            const length = path.getTotalLength();
                            const offset = Math.min(20, length / 3);
                            const fromPt = length ? path.getPointAtLength(offset) : { x: x1, y: y1 };
                            const toPt = length ? path.getPointAtLength(length - offset) : { x: x2, y: y2 };
                            this.renderReconnectHandles(svg, edge, fromPt.x, fromPt.y, toPt.x, toPt.y);
                        }
                    }
                });
                this.updateSelectedConnectionStyling();
            },

            /** Draggable grab points at a selected connection's two endpoints
             *  — see startReconnect/updateReconnectPath/finishReconnect. */
            renderReconnectHandles(svg, edge, x1, y1, x2, y2) {
                ['from', 'to'].forEach(end => {
                    let handle = svg.querySelector(`.wf-reconnect-handle[data-end="${end}"]`);
                    if (! handle) {
                        handle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                        handle.setAttribute('class', 'wf-reconnect-handle');
                        handle.setAttribute('r', 6);
                        handle.dataset.end = end;
                        svg.appendChild(handle);
                    }
                    handle.dataset.edgeId = edge.id;
                    handle.setAttribute('cx', end === 'from' ? x1 : x2);
                    handle.setAttribute('cy', end === 'from' ? y1 : y2);
                });
            },

            startReconnect(handleEl) {
                const svg = handleEl.closest('.connection');
                const edge = this.findEdge(handleEl.dataset.edgeId);
                if (! edge || ! svg) return;
                this.reconnecting = { edgeId: edge.id, end: handleEl.dataset.end, svg };
            },

            /** Redraws the dragged connection live with the grabbed end
             *  following the cursor, and highlights whatever node it's
             *  currently hovering as the drop target. */
            updateReconnectPath(e) {
                const edge = this.findEdge(this.reconnecting.edgeId);
                if (! edge) return;

                const data = this.editor.drawflow.drawflow[this.editor.module].data;
                const fromDf = this.nodeIdToDrawflowId[edge.from];
                const toDf = this.nodeIdToDrawflowId[edge.to];
                const fromNode = data[fromDf], toNode = data[toDf];
                const fromEl = document.getElementById('node-' + fromDf);
                const toEl = document.getElementById('node-' + toDf);
                if (! fromNode || ! toNode || ! fromEl || ! toEl) return;

                const precanvasRect = this.editor.precanvas.getBoundingClientRect();
                const mouseX = (e.clientX - precanvasRect.left) / this.editor.zoom;
                const mouseY = (e.clientY - precanvasRect.top) / this.editor.zoom;

                let x1 = fromNode.pos_x + fromEl.offsetWidth / 2, y1 = fromNode.pos_y + fromEl.offsetHeight;
                let x2 = toNode.pos_x + toEl.offsetWidth / 2, y2 = toNode.pos_y;
                if (this.reconnecting.end === 'from') { x1 = mouseX; y1 = mouseY; }
                else { x2 = mouseX; y2 = mouseY; }

                const path = this.reconnecting.svg.querySelector('.main-path');
                if (path) path.setAttribute('d', this.orthogonalPath(x1, y1, x2, y2));

                const hoverNode = document.elementFromPoint(e.clientX, e.clientY)?.closest('.drawflow-node');
                document.querySelectorAll('.drawflow-node.wf-drop-target').forEach(el => el.classList.remove('wf-drop-target'));
                if (hoverNode) hoverNode.classList.add('wf-drop-target');
            },

            /** Drops onto a node reattaches that end of the edge to it (a
             *  drop that would make the edge point at itself, or anywhere
             *  that isn't a node, is treated as a cancel); either way the
             *  connection is redrawn from the graph's own state afterward,
             *  which snaps a cancelled drag straight back to where it was. */
            finishReconnect(e) {
                if (! this.reconnecting) return;
                const { edgeId, end } = this.reconnecting;
                this.reconnecting = null;
                document.querySelectorAll('.drawflow-node.wf-drop-target').forEach(el => el.classList.remove('wf-drop-target'));

                const edge = this.findEdge(edgeId);
                const targetEl = document.elementFromPoint(e.clientX, e.clientY)?.closest('.drawflow-node');
                if (edge && targetEl) {
                    const newNodeId = this.drawflowIdToNodeId[targetEl.id.replace('node-', '')];
                    const otherEnd = end === 'from' ? edge.to : edge.from;
                    if (newNodeId && newNodeId !== otherEnd) {
                        const oldFromDf = this.nodeIdToDrawflowId[edge.from];
                        const oldToDf = this.nodeIdToDrawflowId[edge.to];
                        this.suppressEvents = true;
                        try { this.editor.removeSingleConnection(oldFromDf, oldToDf, 'output_1', 'input_1'); } catch (err) {}

                        if (end === 'from') edge.from = newNodeId; else edge.to = newNodeId;

                        try { this.editor.addConnection(this.nodeIdToDrawflowId[edge.from], this.nodeIdToDrawflowId[edge.to], 'output_1', 'input_1'); } catch (err) {}
                        this.suppressEvents = false;
                    }
                }

                this.redrawOrthogonalConnections();
            },

            /** Keeps each connection SVG's `wf-selected` class in sync with
             *  `this.selected`. Deliberately not relying on Drawflow's own
             *  "selected" class — it lands on whichever literal <path> element
             *  was actually clicked, which may be our wide invisible hit-path
             *  rather than the visible line, so it can't drive visual styling. */
            updateSelectedConnectionStyling() {
                document.querySelectorAll('#workflow-canvas .connection').forEach(svg => {
                    const inClass = Array.from(svg.classList).find(c => c.startsWith('node_in_'));
                    const outClass = Array.from(svg.classList).find(c => c.startsWith('node_out_'));
                    if (! inClass || ! outClass) return;
                    const fromNodeId = this.drawflowIdToNodeId[outClass.replace('node_out_node-', '')];
                    const toNodeId = this.drawflowIdToNodeId[inClass.replace('node_in_node-', '')];
                    const edge = this.graph.edges.find(e => e.from === fromNodeId && e.to === toNodeId);
                    const isSelected = !! (edge && this.selected?.kind === 'edge' && this.selected.id === edge.id);
                    svg.classList.toggle('wf-selected', isSelected);
                    // SVGs stack in DOM order, so a connection that overlaps
                    // another one can end up visually underneath it — moving
                    // the selected one to be the last child of its parent
                    // brings it (and its label/reconnect handles) to the top.
                    if (isSelected) svg.parentNode.appendChild(svg);
                });
            },

            /** Keeps a wide, invisible twin of the connection's path in sync —
             *  see the .wf-hit-path CSS comment for why this exists. Always
             *  appended (never inserted first) so `svg.querySelector('.main-path')`
             *  elsewhere keeps resolving to Drawflow's own original element. */
            ensureHitPath(svg, d) {
                let hit = svg.querySelector('.wf-hit-path');
                if (! hit) {
                    hit = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    hit.setAttribute('class', 'main-path wf-hit-path');
                    svg.appendChild(hit);
                }
                hit.setAttribute('d', d);
            },

            /** Renders (or updates) the edge's label, centered along the middle
             *  of its already-drawn path — using getPointAtLength so it stays
             *  centered regardless of how many bends the orthogonal route took. */
            updateConnectionLabel(svg, edge) {
                const path = svg.querySelector('.main-path');
                if (! path) return;
                const text = edge.button_label || edge.name || edge.id;

                let bg = svg.querySelector('.wf-edge-label-bg');
                let label = svg.querySelector('.wf-edge-label');
                if (! label) {
                    bg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                    bg.setAttribute('class', 'wf-edge-label-bg');
                    svg.appendChild(bg);
                    label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    label.setAttribute('class', 'wf-edge-label');
                    svg.appendChild(label);
                }

                label.textContent = text;

                const length = path.getTotalLength();
                const mid = length ? path.getPointAtLength(length / 2) : { x: 0, y: 0 };
                label.setAttribute('x', mid.x);
                label.setAttribute('y', mid.y);

                const bbox = label.getBBox();
                const padX = 4, padY = 2;
                bg.setAttribute('x', bbox.x - padX);
                bg.setAttribute('y', bbox.y - padY);
                bg.setAttribute('width', bbox.width + padX * 2);
                bg.setAttribute('height', bbox.height + padY * 2);
            },

            /** Keeps the connector angled while it's still being dragged out
             *  from an output dot, before it's dropped onto a target. */
            updateDraggingConnectionPath(e) {
                const outEl = this.editor.ele_selected ? this.editor.ele_selected.closest('.drawflow-node') : null;
                if (! outEl || ! this.editor.connection_ele) return;

                const outId = outEl.id.replace('node-', '');
                const outNode = this.editor.drawflow.drawflow[this.editor.module].data[outId];
                if (! outNode) return;

                const precanvasRect = this.editor.precanvas.getBoundingClientRect();
                const x1 = outNode.pos_x + outEl.offsetWidth / 2;
                const y1 = outNode.pos_y + outEl.offsetHeight;
                const x2 = (e.clientX - precanvasRect.left) / this.editor.zoom;
                const y2 = (e.clientY - precanvasRect.top) / this.editor.zoom;

                const path = this.editor.connection_ele.querySelector('.main-path');
                if (path) path.setAttribute('d', this.orthogonalPath(x1, y1, x2, y2));
            },

            // ---------- Minimap ----------

            updateMinimap() {
                const inner = document.getElementById('workflow-minimap-inner');
                if (! inner || ! this.editor) return;

                const data = this.editor.drawflow.drawflow[this.editor.module].data;
                const boxes = [];
                let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;

                Object.keys(data).forEach(id => {
                    const n = data[id];
                    const el = document.getElementById('node-' + id);
                    const w = el ? el.offsetWidth : 150, h = el ? el.offsetHeight : 50;
                    boxes.push({ x: n.pos_x, y: n.pos_y, w, h });
                    minX = Math.min(minX, n.pos_x);
                    minY = Math.min(minY, n.pos_y);
                    maxX = Math.max(maxX, n.pos_x + w);
                    maxY = Math.max(maxY, n.pos_y + h);
                });

                if (! isFinite(minX)) {
                    inner.innerHTML = '';
                    return;
                }

                const pad = 30;
                minX -= pad; minY -= pad; maxX += pad; maxY += pad;
                const spanX = Math.max(maxX - minX, 1), spanY = Math.max(maxY - minY, 1);
                const scale = Math.min(inner.clientWidth / spanX, inner.clientHeight / spanY);

                inner.innerHTML = boxes.map(b => {
                    const x = (b.x - minX) * scale, y = (b.y - minY) * scale;
                    const w = Math.max(4, b.w * scale), h = Math.max(4, b.h * scale);
                    return `<div style="position:absolute;left:${x}px;top:${y}px;width:${w}px;height:${h}px;background:#7c3aed;opacity:.55;border-radius:2px;"></div>`;
                }).join('');
            },

            // ---------- Inspector rendering (structured UI, no raw JSON) ----------
            // Rendered via innerHTML + delegated events rather than nested Alpine
            // templates, since the precondition tree is genuinely recursive and
            // Alpine's <template> directives don't recurse cleanly.

            renderNodeInspector() {
                const node = this.findNode(this.selected.id);
                if (! node) return '';
                let html = `<h5>Node: ${node.id}</h5>`;
                // html += this.readonlyField('id', node.id);
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
                // html += this.readonlyField('id', edge.id);
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

            readonlyField(label, value) {
                return `<div class="form-group mb-2">
                    <label class="mb-0 small">${label}</label>
                    <input type="text" class="form-control form-control-sm" value="${(value ?? '').toString().replace(/"/g, '&quot;')}" readonly>
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

            // ---------- Field policy modal (node inspector "Edit field policy") ----------

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

            // ---------- Save ----------

            /** Serializes the current canvas state into the graph shape the
             *  backend expects. Shared by the AJAX draft save and the normal
             *  Publish form submit, so the two can never drift apart. */
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
    }
</script>
@endsection
