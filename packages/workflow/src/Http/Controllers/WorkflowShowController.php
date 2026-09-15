<?php

namespace Workflow\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Workflow\HasWorkflow;
use Workflow\Models\WorkflowDefinition;
use Workflow\Support\InlineFieldCrudStub;
use Workflow\Support\SafeLocalUrl;
use Workflow\Support\WorkflowInlineFieldRenderer;
use Workflow\Support\WorkflowStatusPresenter;

/**
 * The "show-workflow" page linked to from the generic workflow_show button in
 * the actions column: the current node's header_view, its field policy (in
 * the order configured in the node inspector — no Backpack CrudController
 * form involved), the current node's footer_view, and the
 * record_button-surfaced transitions available to the logged-in user at the
 * bottom. A field_policy entry marked visible + not-readonly renders as a
 * genuinely editable input right here (see WorkflowInlineFieldRenderer/
 * WorkflowInlineUpdateController) — everything else renders as a plain
 * read-only column, same as before. Kept generic (not per-model) the same
 * way WorkflowTransitionController is, so any HasWorkflow model gets this
 * for free.
 */
class WorkflowShowController
{
    public function __invoke(Request $request, WorkflowStatusPresenter $presenter, WorkflowInlineFieldRenderer $renderer): View
    {
        $validated = $request->validate([
            'workflowable_type' => 'required|string',
            'workflowable_id' => 'required',
            'return_to' => 'nullable|string',
        ]);

        $class = $validated['workflowable_type'];

        if (! class_exists($class) || ! in_array(HasWorkflow::class, class_uses_recursive($class))) {
            abort(404);
        }

        $workflowable = $class::findOrFail($validated['workflowable_id']);
        $instance = $workflowable->workflowInstance();
        $node = $renderer->currentNode($instance);

        $columns = $node ? $renderer->split($node, $workflowable) : collect();
        $inlineEditCrud = new InlineFieldCrudStub($workflowable);

        $status = $presenter->present($workflowable, backpack_auth()->user());

        // The effective version's own display_name when there's an active
        // instance; otherwise fall back to the definition's own (draft or
        // published) — see WorkflowDefinition{,Version}::displayName().
        $displayName = $instance
            ? $instance->effectiveVersion()->displayName()
            : (WorkflowDefinition::where('model', $class)->first()?->displayName() ?? Str::headline(class_basename($class)));

        return view('workflow::show', [
            'workflowable' => $workflowable,
            'displayName' => $displayName,
            'node' => $node,
            'columns' => $columns,
            'hasEditableFields' => $columns->contains('editable', true),
            'inlineEditCrud' => $inlineEditCrud,
            'status' => $status,
            // Threaded through each transition form as a hidden field, so
            // WorkflowTransitionController can send the user back to
            // wherever they actually came from (the model's own list page,
            // via workflow_show.blade.php's link) instead of reloading this
            // same show-workflow page. Only ever trusted if it points at
            // this same app — never redirect to an attacker-supplied host.
            'returnTo' => SafeLocalUrl::resolve($validated['return_to'] ?? null),
        ]);
    }
}
