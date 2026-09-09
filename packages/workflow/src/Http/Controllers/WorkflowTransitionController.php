<?php

namespace Workflow\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Workflow\HasWorkflow;
use Workflow\Support\TransitionEngine;

/**
 * The single endpoint every "workflow" column/field transition button posts
 * to. Kept generic (not per-model) so any HasWorkflow model gets this for
 * free — this is what makes the column a true drop-in, no controller code
 * required on the downstream CrudController for the common case.
 */
class WorkflowTransitionController
{
    public function __invoke(Request $request, TransitionEngine $engine): RedirectResponse
    {
        $validated = $request->validate([
            'workflowable_type' => 'required|string',
            'workflowable_id' => 'required',
            'edge_id' => 'required|string',
            'inputs' => 'array',
        ]);

        $class = $validated['workflowable_type'];

        if (! class_exists($class) || ! in_array(HasWorkflow::class, class_uses_recursive($class))) {
            abort(404);
        }

        $workflowable = $class::findOrFail($validated['workflowable_id']);
        $instance = $workflowable->workflowInstance();

        if (! $instance) {
            return back()->with('error', 'This record has no active workflow instance.');
        }

        $edge = $instance->version->edge($validated['edge_id']);
        $token = $instance->activeTokens()->where('node_id', $edge['from'] ?? null)->first();

        if (! $token) {
            return back()->with('error', 'That transition is no longer available from the record\'s current state.');
        }

        $history = $engine->transition($token, $validated['edge_id'], $validated['inputs'] ?? [], backpack_auth()->user());

        if (! $history) {
            return back()->with('error', 'That transition could not be completed — check you have permission and any preconditions are met.');
        }

        return back()->with('message', 'Transitioned to '.$history->to_node_id.'.');
    }
}
