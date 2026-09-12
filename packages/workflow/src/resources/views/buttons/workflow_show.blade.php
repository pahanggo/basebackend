{{--
    Icon-only link in the "Tindakan"/actions column (the 'line' button
    stack), alongside show/edit/delete, opening the generic "show-workflow"
    page (see Workflow\Http\Controllers\WorkflowShowController and
    packages/workflow/src/resources/views/show.blade.php) for this row's
    record: the current node's header_view, its field policy as a read-only
    column list, its footer_view, then the available transitions — this is
    the ONLY place a transition is actually fired from; the row itself never
    renders an inline transition button, by design (see this button's own
    registration in WorkflowOperation::setupWorkflowOperationDefaults() —
    workflow_transitions/workflow_transition_assets were removed).

    The icon itself is hidden entirely once there's nothing left for the
    logged-in user to act on for this record — on both the list and the
    Backpack show page — since the icon's whole purpose here is to reach
    the transitions on the show-workflow page; with none available, it's
    just a dead-end link. A small red dot always overlays it when shown
    (there's always at least one action once it's visible at all), so a
    list full of rows still makes it obvious at a glance which ones need
    attention.
--}}
@php
    $workflowHasNextActions = ! empty(
        app(\Workflow\Support\WorkflowStatusPresenter::class)
            ->present($entry, backpack_auth()->user(), 'record_button')['transitions']
    );
@endphp

@if ($workflowHasNextActions)
    <a href="{{ route('workflow.show', ['workflowable_type' => get_class($entry), 'workflowable_id' => $entry->getKey(), 'return_to' => url($crud->route)]) }}"
       class="btn btn-sm btn-link position-relative" data-toggle="tooltip" title="Workflow">
        <i class="la la-sitemap"></i><span class="sr-only">Workflow</span>
        {{-- Inline styles, not a <style>/@push block: list renders this row
             exclusively through the AJAX /search endpoint, whose isolated
             render silently drops any @push/@stack content (see the sibling
             workflow_transitions history for the same pitfall) — only this
             element's own HTML actually reaches the page either way. --}}
        <span title="Action needed" style="position: absolute; top: 4px; right: 4px; width: 8px; height: 8px; border-radius: 50%; background-color: #dc3545; display: inline-block;"></span>
    </a>
@endif
