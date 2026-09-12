<?php

namespace Workflow\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Workflow\HasWorkflow;
use Workflow\Support\WorkflowTimeline;

/**
 * Backs the "... N more" link at the bottom of a truncated workflow timeline
 * (see packages/workflow/src/resources/views/inc/workflow_timeline.blade.php, which only
 * renders the 3 most recent steps up front): returns the remaining steps —
 * from the given offset onward — as a bare HTML fragment
 * (workflow::inc.workflow_timeline_items, the same partial the initial 3 render
 * through) for the caller's JS to append in place, rather than a full page
 * reload. Kept generic (not per-model), same pattern as
 * WorkflowShowController/WorkflowTransitionController.
 */
class WorkflowTimelineController
{
    public function __invoke(Request $request, WorkflowTimeline $timeline): View
    {
        $validated = $request->validate([
            'workflowable_type' => 'required|string',
            'workflowable_id' => 'required',
            'offset' => 'nullable|integer|min:0',
        ]);

        $class = $validated['workflowable_type'];

        if (! class_exists($class) || ! in_array(HasWorkflow::class, class_uses_recursive($class), true)) {
            abort(404);
        }

        $workflowable = $class::findOrFail($validated['workflowable_id']);
        $items = array_slice($timeline->build($workflowable), (int) ($validated['offset'] ?? 0));

        return view('workflow::inc.workflow_timeline_items', ['items' => $items]);
    }
}
