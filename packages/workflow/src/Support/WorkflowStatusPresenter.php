<?php

namespace Workflow\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Workflow\Models\WorkflowInstance;

/**
 * Computes what the "workflow" column/field type needs to render for one
 * workflowable record: its active tokens' node labels, and which manual,
 * record_button-surfaced transitions the given actor may fire right now
 * (actor_rule and preconditions both already checked) — so the Blade view
 * never has to reach into TransitionEngine internals itself.
 */
class WorkflowStatusPresenter
{
    public function __construct(
        protected PreconditionEvaluator $preconditions,
        protected ActorRuleResolver $actorRules,
    ) {
    }

    /**
     * @return array{
     *     instance: WorkflowInstance|null,
     *     tokens: array<int, array{node_id: string, label: string}>,
     *     transitions: array<int, array{edge_id: string, label: string, requires_confirmation: bool, inputs: array}>,
     * }
     */
    public function present(Model $workflowable, ?Authenticatable $actor, string $surface = 'record_button'): array
    {
        $instance = $workflowable->workflowInstance();

        if (! $instance) {
            return ['instance' => null, 'tokens' => [], 'transitions' => []];
        }

        $version = $instance->effectiveVersion();
        $tokens = $instance->activeTokens;

        $tokenLabels = $tokens
            ->map(fn ($token) => [
                'node_id' => $token->node_id,
                'label' => $version->node($token->node_id)['name'] ?? $token->node_id,
            ])
            ->values()
            ->all();

        $transitions = $tokens
            ->flatMap(fn ($token) => $version->edgesFrom($token->node_id))
            ->filter(function (array $edge) use ($surface, $workflowable, $actor) {
                if (($edge['trigger'] ?? 'manual') !== 'manual') {
                    return false;
                }

                if (! in_array($surface, $edge['surfaces'] ?? ['record_button'], true)) {
                    return false;
                }

                if (! $this->actorRules->allows($edge['actor_rule'] ?? null, $actor, $workflowable)) {
                    return false;
                }

                return $this->preconditions->passes($edge['preconditions'] ?? null, $workflowable);
            })
            ->map(fn (array $edge) => [
                'edge_id' => $edge['id'],
                'label' => ($edge['button_label'] ?? null) ?: ($edge['name'] ?? $edge['id']),
                'requires_confirmation' => (bool) ($edge['requires_confirmation'] ?? false),
                'inputs' => $edge['inputs'] ?? [],
            ])
            ->values()
            ->all();

        return ['instance' => $instance, 'tokens' => $tokenLabels, 'transitions' => $transitions];
    }
}
