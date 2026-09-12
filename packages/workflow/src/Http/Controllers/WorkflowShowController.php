<?php

namespace Workflow\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Workflow\HasWorkflow;
use Workflow\Models\WorkflowDefinition;
use Workflow\Support\CustomFieldDefinitionParser;
use Workflow\Support\SafeLocalUrl;
use Workflow\Support\WorkflowStatusPresenter;

/**
 * The "show-workflow" page linked to from the generic workflow_show button in
 * the actions column: the current node's header_view, its field policy
 * rendered as a plain read-only column list (in the order configured in the
 * node inspector — no Backpack field/form involved, this is a summary view,
 * not an edit form), the current node's footer_view, and the
 * record_button-surfaced transitions available to the logged-in user at the
 * bottom. Kept generic (not per-model) the same way WorkflowTransitionController
 * is, so any HasWorkflow model gets this for free.
 */
class WorkflowShowController
{
    public function __invoke(Request $request, WorkflowStatusPresenter $presenter): View
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
        $token = $instance?->activeTokens()->first();
        $node = $token ? $instance->effectiveVersion()->node($token->node_id) : null;

        // Built as real Backpack column definitions (name/label/type, plus
        // whatever custom_field_definition merges in — e.g. a money field's
        // prefix/suffix) so show.blade.php can render each one through the
        // actual crud::columns.{type} partial instead of a raw scalar dump,
        // matching what the same field policy would produce on the model's
        // Backpack show/list column.
        $columns = collect($node['field_policy'] ?? [])
            ->filter(fn (array $entry) => ($entry['visible'] ?? true) !== false)
            ->map(function (array $entry) {
                $column = [
                    'name' => $entry['field'],
                    'label' => ($entry['label'] ?? null) ?: Str::headline(str_replace('.', ' ', $entry['field'])),
                    'type' => $entry['type'] ?? 'text',
                ];

                if (! empty($entry['custom_field_definition'])) {
                    $decoded = (new CustomFieldDefinitionParser)->parse((string) $entry['custom_field_definition']);

                    if (is_array($decoded)) {
                        $column = array_merge($column, $decoded);
                    }
                }

                return $column;
            })
            ->values();

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
