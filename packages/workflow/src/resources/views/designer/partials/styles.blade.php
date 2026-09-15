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
    .wf-transition-row { border: 1px solid #e9ecef; border-radius: 4px; padding: .35rem .5rem; margin-bottom: .35rem; background: #fafbfc; cursor: pointer; display: flex; align-items: center; gap: .35rem; }
    .wf-transition-row:hover { background: #eef1ff; border-color: var(--primary); }
    .wf-group-box { border: 1px dashed #adb5bd; border-radius: 4px; padding: .5rem; margin-bottom: .5rem; }
    p.version { position: absolute;top: 60px;left: 14px;pointer-events: none;z-index: 1; }

    #wf-field-policy-table td { vertical-align: middle; min-width: 90px; }
    #wf-field-policy-table td textarea { min-width: 140px; }
    #wf-field-policy-table td .wf-custom-field-definition { min-width: 320px; font-size: 12px; }
    .wf-field-policy-row { cursor: default; }
    .wf-field-policy-row.wf-dragging { opacity: .4; }
    .wf-field-policy-row .wf-drag-handle { cursor: grab; }
</style>
