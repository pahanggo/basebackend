<?php

namespace Workflow\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Workflow\Models\WorkflowDefinition;

/**
 * The canvas/inspector editor — deliberately a standalone controller/view,
 * not a Backpack CRUD or custom field, since it's a full-page interactive
 * experience that doesn't fit a form-field container. See the "Package
 * structure & distribution" and "Backpack-facing surface" sections of the
 * architecture plan.
 *
 * Saving always creates a new immutable workflow_definition_versions row and
 * publishes it — existing instances stay pinned to whichever version they
 * started on, so this never disturbs in-flight workflows.
 */
class WorkflowDesignerController
{
    public function edit(WorkflowDefinition $workflowDefinition): View
    {
        $graph = $workflowDefinition->publishedVersion?->graph ?? ['start' => null, 'nodes' => [], 'edges' => []];

        return view('workflow::designer.edit', [
            'definition' => $workflowDefinition,
            'graph' => $graph,
        ]);
    }

    public function update(Request $request, WorkflowDefinition $workflowDefinition): RedirectResponse
    {
        $validated = $request->validate([
            'graph' => 'required|json',
        ]);

        $graph = json_decode($validated['graph'], true, flags: JSON_THROW_ON_ERROR);

        $nextVersionNumber = ($workflowDefinition->versions()->max('version') ?? 0) + 1;

        $version = $workflowDefinition->versions()->create([
            'version' => $nextVersionNumber,
            'graph' => $graph,
            'published_at' => now(),
        ]);

        $workflowDefinition->update(['published_version_id' => $version->id]);

        return redirect()
            ->route('workflow.designer.edit', $workflowDefinition)
            ->with('message', "Saved as version {$nextVersionNumber} and published. In-flight instances on earlier versions are unaffected.");
    }
}
