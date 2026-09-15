<?php

namespace Workflow\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Workflow\HasWorkflow;
use Workflow\Models\WorkflowInstance;
use Workflow\Models\WorkflowInstanceHistory;
use Workflow\Support\SafeLocalUrl;
use Workflow\Support\WorkflowInlineFieldRenderer;

/**
 * Saves a node's inline-editable field_policy fields straight from the
 * show-workflow page — deliberately independent of the model's own
 * Backpack Update operation (operation_settings/row_actions.update): a
 * field marked visible + not-readonly in the node inspector is editable
 * here regardless of whether Update itself is enabled, and this endpoint
 * never touches CRUD::denyAccess() or the Edit button/route at all. That's
 * what lets a node grant "edit just these fields, right here" without also
 * exposing the model's full Update form/button. See
 * WorkflowInlineFieldRenderer's own docblock for the authorization
 * reasoning (same posture as the show-workflow page itself).
 */
class WorkflowInlineUpdateController
{
    public function __invoke(Request $request, WorkflowInlineFieldRenderer $renderer): RedirectResponse
    {
        $validated = $request->validate([
            'workflowable_type' => 'required|string',
            'workflowable_id' => 'required',
            'return_to' => 'nullable|string',
        ]);

        $class = $validated['workflowable_type'];

        if (! class_exists($class) || ! in_array(HasWorkflow::class, class_uses_recursive($class), true)) {
            abort(404);
        }

        $workflowable = $class::findOrFail($validated['workflowable_id']);
        $instance = $workflowable->workflowInstance();
        $node = $renderer->currentNode($instance);
        $returnTo = SafeLocalUrl::resolve($validated['return_to'] ?? null) ?? url()->previous();

        // The show-workflow page itself, so the record's owner reloads what
        // they just edited (and its timeline entry) instead of bouncing
        // back to wherever they came from — unlike a transition, saving an
        // inline edit doesn't move the record anywhere.
        $samePage = route('workflow.show', array_filter([
            'workflowable_type' => $class,
            'workflowable_id' => $workflowable->getKey(),
            'return_to' => $validated['return_to'] ?? null,
        ]));

        if (! $node) {
            \Alert::error('This record has no active workflow instance.')->flash();

            return redirect($returnTo);
        }

        $editable = $renderer->editableFieldNames($node);

        if (empty($editable)) {
            \Alert::error('Nothing on this screen is currently editable.')->flash();

            return redirect($returnTo);
        }

        $data = [];

        foreach ($editable as $field => $entry) {
            $isBoolean = in_array($entry['type'] ?? 'text', ['checkbox', 'boolean', 'switch'], true);

            if ($isBoolean) {
                $data[$field] = $request->boolean($field);
            } elseif ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        $before = $workflowable->only(array_keys($data));
        $workflowable->fill($data)->save();
        $this->recordEditHistory($instance, $node, $editable, $before, $workflowable, $request);

        \Alert::success('Saved.')->flash();

        return redirect($samePage);
    }

    /**
     * Logs a non-transitioning "edited these fields" step onto the same
     * workflow_instance_history table transitions use (see WorkflowTimeline,
     * which already renders it) — from_node_id/to_node_id both stay the
     * current node (nothing moved), edge_id is null (no edge fired), and
     * `inputs` carries each changed field's before/after value plus a
     * humanized label, keyed distinctly from a transition's own captured
     * inputs so WorkflowTimeline can render them without an edge to look up.
     */
    protected function recordEditHistory(
        ?WorkflowInstance $instance,
        array $node,
        array $editable,
        array $before,
        Model $workflowable,
        Request $request
    ): void {
        if (! $instance) {
            return;
        }

        $changed = [];

        foreach ($before as $field => $oldValue) {
            $newValue = $workflowable->getAttribute($field);

            if ($oldValue == $newValue) {
                continue;
            }

            $changed[$field] = [
                'label' => $editable[$field]['label'] ?? Str::headline($field),
                'from' => $oldValue,
                'to' => $newValue,
            ];
        }

        if (empty($changed)) {
            return;
        }

        $token = $instance->activeTokens()->first();
        $actor = backpack_auth()->user() ?? $request->user();

        WorkflowInstanceHistory::create([
            'workflow_instance_id' => $instance->id,
            'workflow_instance_token_id' => $token?->id,
            'from_node_id' => $node['id'],
            'to_node_id' => $node['id'],
            'edge_id' => null,
            'trigger' => 'edit',
            'actor_type' => $actor ? $actor::class : null,
            'actor_id' => $actor?->getAuthIdentifier(),
            'inputs' => $changed,
            'created_at' => now(),
        ]);
    }
}
