<?php

namespace Workflow\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Workflow\Models\WorkflowDefinition;

/**
 * Scopes a list query down to only the rows a given actor is allowed to see
 * at all — independent of workflow state, and a different concern entirely
 * from the show/update/delete/create operation_settings (those gate acting
 * on a record you can already see; this gates seeing it in the first
 * place). Wired into the 'list' operation by
 * Workflow\Http\Controllers\Operations\WorkflowOperation.
 *
 * Definition-level `visibility_rules`: an ORDERED list of
 * `{actor_rule, scope, ...}` entries — evaluated top to bottom, the first
 * whose `actor_rule` matches the current actor wins (same actor_rule shape
 * and resolution as everywhere else in the engine — see ActorRuleResolver).
 * `scope` is one of:
 *
 *   - 'all'            No restriction at all — e.g. a role that processes
 *                      every record regardless of who owns it.
 *   - 'owner'          `owner_field` on the model must equal the actor's id
 *                      — e.g. "employees see only their own requests."
 *   - 'model_callback' Calls `$model->{model_callback}($query, $actor)`,
 *                      applying whatever the method itself adds to $query —
 *                      the escape hatch for anything the two no-code scopes
 *                      above can't express (e.g. "same department as me"),
 *                      matching the same model_callback convention
 *                      actor_rule and preconditions already use elsewhere.
 *
 * No `visibility_rules` configured at all is the default and leaves the
 * query completely untouched — this feature is opt-in, so it can never
 * silently break a definition that never configured it. Once ANY rules
 * exist, though, an actor matching none of them sees nothing at all — deny
 * by default, mirroring actor_rule's own "no rule declared → anyone" vs.
 * "a rule IS declared → only matching actors" distinction.
 */
class WorkflowVisibilityScope
{
    public function __construct(protected ActorRuleResolver $actorRules)
    {
    }

    public function apply(Builder $query, string $workflowableClass, ?Authenticatable $actor): void
    {
        $rules = WorkflowDefinition::where('model', $workflowableClass)->first()?->publishedVersion?->graph['visibility_rules'] ?? [];

        if (empty($rules)) {
            return;
        }

        foreach ($rules as $rule) {
            if ($this->actorRules->allows($rule['actor_rule'] ?? null, $actor)) {
                $this->applyScope($query, $rule, $actor);

                return;
            }
        }

        // No rule matched this actor at all — deny by default.
        $query->whereRaw('1 = 0');
    }

    protected function applyScope(Builder $query, array $rule, ?Authenticatable $actor): void
    {
        match ($rule['scope'] ?? null) {
            'all' => null,
            'owner' => $query->where($rule['owner_field'] ?: 'user_id', $actor?->getAuthIdentifier()),
            'model_callback' => $this->applyModelCallback($query, $rule, $actor),
            default => $query->whereRaw('1 = 0'),
        };
    }

    protected function applyModelCallback(Builder $query, array $rule, ?Authenticatable $actor): void
    {
        $callback = $rule['model_callback'] ?? null;
        $model = $query->getModel();

        if ($callback && method_exists($model, $callback)) {
            $model->{$callback}($query, $actor);

            return;
        }

        $query->whereRaw('1 = 0');
    }
}
