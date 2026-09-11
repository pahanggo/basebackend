{{-- Float toolbar, "editing version..." caption, minimap, and zoom controls
     that sit on top of #workflow-canvas. Split out of edit.blade.php purely to
     keep that file navigable — this partial has no state of its own, it only
     calls methods/reads state off the workflowDesigner() Alpine component. --}}
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
