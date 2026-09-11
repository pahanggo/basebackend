{{-- Right-hand inspector panel — shows nothing/node-inspector/edge-inspector
     depending on `selected`, rendered as raw HTML (x-html) rather than nested
     Alpine templates since the precondition tree is genuinely recursive (see
     scripts/inspector.blade.php for why). --}}
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
