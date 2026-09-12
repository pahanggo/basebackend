<?php

namespace Workflow\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Workflow\HasWorkflow;
use Workflow\Support\SafeLocalUrl;
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
            'return_to' => 'nullable|string',
        ]);

        $class = $validated['workflowable_type'];

        if (! class_exists($class) || ! in_array(HasWorkflow::class, class_uses_recursive($class))) {
            abort(404);
        }

        $workflowable = $class::findOrFail($validated['workflowable_id']);
        $instance = $workflowable->workflowInstance();

        if (! $instance) {
            return $this->redirectBack(false, 'This record has no active workflow instance.');
        }

        $edge = $instance->effectiveVersion()->edge($validated['edge_id']);
        $token = $instance->activeTokens()->where('node_id', $edge['from'] ?? null)->first();

        if (! $token) {
            return $this->redirectBack(false, 'That transition is no longer available from the record\'s current state.');
        }

        $history = $engine->transition($token, $validated['edge_id'], $validated['inputs'] ?? [], backpack_auth()->user());

        if (! $history) {
            return $this->redirectBack(false, 'That transition could not be completed — check you have permission and any preconditions are met.');
        }

        return $this->redirectBack(true, 'Transitioned to '.$history->to_node_id.'.');
    }

    /**
     * Every transition now fires exclusively from the "show-workflow" page
     * (see packages/workflow/src/resources/views/show.blade.php) — this
     * sends the user back to wherever they actually came from (that page's
     * own workflow_show.blade.php link carries the underlying model's list
     * URL as `return_to`) instead of reloading the show-workflow page
     * itself, with a real Backpack (Prologue Alerts) flash banner rather
     * than a plain session key nothing ever rendered. Re-validates
     * `return_to` via SafeLocalUrl itself rather than trusting
     * WorkflowShowController already did — this is a separate endpoint
     * anyone could POST to directly with an attacker-supplied value,
     * otherwise a plain open-redirect vector. Falls back to back() when no
     * (valid) return_to was given — e.g. a direct API call, or an older
     * bookmarked show-workflow link from before this.
     */
    protected function redirectBack(bool $success, string $message): RedirectResponse
    {
        $success ? \Alert::success($message)->flash() : \Alert::error($message)->flash();

        $returnTo = SafeLocalUrl::resolve(request('return_to'));

        return $returnTo ? redirect($returnTo) : back();
    }
}
