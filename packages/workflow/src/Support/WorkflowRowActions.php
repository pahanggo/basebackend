<?php

namespace Workflow\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Workflow\HasWorkflow;
use Workflow\Models\WorkflowDefinition;

/**
 * The single authority for whether a record's show/update/delete action is
 * allowed right now — checked in precedence order:
 *
 *   1. The record's *current node* declares its own row_actions override
 *      for this operation (set in the node inspector) — use that, full
 *      stop, regardless of what the definition-level setting says. This is
 *      what makes it a genuine override rather than an additional
 *      restriction: e.g. Update can be globally disabled for this model,
 *      but a specific node (say 'draft', while the requester can still
 *      correct their own request) re-enables it just for records sitting
 *      there.
 *   2. No node override for this operation — defer to the definition-level
 *      operation_settings (the toolbar's gear-icon modal), read straight
 *      off the same graph version the node came from (no separate
 *      WorkflowDefinition lookup needed).
 *   3. Neither configured — allowed.
 *
 * Used both to decide whether a row's show/update/delete button renders
 * (crud/buttons/workflow_gated_*.blade.php) and, for the single record a
 * show/update/delete *request* is actually about, to gate the operation
 * itself (WorkflowOperation::applyWorkflowOperationAccess()) — the same
 * check enforces both, so a hidden button is never just cosmetic.
 */
class WorkflowRowActions
{
    public function __construct(protected ActorRuleResolver $actorRules)
    {
    }

    public function allowed(Model $entry, string $operation, ?Authenticatable $actor): bool
    {
        if (! in_array(HasWorkflow::class, class_uses_recursive($entry), true)) {
            return true;
        }

        $instance = $entry->workflowInstance();

        if (! $instance) {
            return true;
        }

        $version = $instance->effectiveVersion();
        $token = $instance->activeTokens()->first();
        $node = $token ? $version->node($token->node_id) : null;
        $override = $node['row_actions'][$operation] ?? null;

        $setting = $override ?? ($version->graph['operation_settings'][$operation] ?? null);

        if ($setting === null) {
            return true;
        }

        if (($setting['enabled'] ?? true) === false) {
            return false;
        }

        return $this->actorRules->allows($setting['actor_rule'] ?? null, $actor, $entry);
    }

    /**
     * The 'create' counterpart to allowed() — there's no entry (or node) yet
     * for a not-yet-existing record, so this is purely the definition-level
     * operation_settings.create actor_rule, with no per-node row_actions
     * override possible (a node is where a record's *current state*
     * already lives). A model_callback in that actor_rule is simply
     * inert here (see ActorRuleResolver::allows()'s own null-$model guard),
     * same as it would be for any actor_rule check with nothing yet to call
     * the callback on.
     */
    public function allowedToCreate(string $workflowableClass, ?Authenticatable $actor): bool
    {
        if (! in_array(HasWorkflow::class, class_uses_recursive($workflowableClass), true)) {
            return true;
        }

        $version = WorkflowDefinition::where('model', $workflowableClass)->first()?->publishedVersion;
        $setting = $version?->graph['operation_settings']['create'] ?? null;

        if ($setting === null) {
            return true;
        }

        if (($setting['enabled'] ?? true) === false) {
            return false;
        }

        return $this->actorRules->allows($setting['actor_rule'] ?? null, $actor);
    }
}
