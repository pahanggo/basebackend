<?php

namespace Workflow\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Workflow\Models\WorkflowDefinitionVersion;
use Workflow\Registries\WorkflowActionRegistry;
use RuntimeException;

/**
 * Powers the designer's "Test with a sample record" dry-run mode: walks a
 * real record through an (often still-unpublished, in-editor) graph without
 * ever persisting a WorkflowInstance/Token/History row, dispatching a real
 * transitioning/transitioned event, or executing a real action — actions
 * report their preview() text instead (see WorkflowActionType). Nothing here
 * touches the `workflow` database connection at all.
 */
class WorkflowSimulator
{
    public function __construct(
        protected PreconditionEvaluator $preconditions,
        protected ActorRuleResolver $actorRules,
        protected WorkflowActionRegistry $actions,
    ) {
    }

    /**
     * Describes a node's outgoing edges against a specific record/actor —
     * whether each would currently be available — without firing any of them.
     *
     * @return array{node: array, edges: array<int, array>}
     */
    public function describe(WorkflowDefinitionVersion $version, Model $workflowable, ?Authenticatable $actor, string $nodeId): array
    {
        $node = $version->node($nodeId);

        if (! $node) {
            throw new RuntimeException("Unknown node [{$nodeId}] in this graph.");
        }

        $edges = collect($version->edgesFrom($nodeId))
            ->map(function (array $edge) use ($workflowable, $actor) {
                $preconditionsPass = $this->preconditions->passes($edge['preconditions'] ?? null, $workflowable);
                $isManual = ($edge['trigger'] ?? 'manual') === 'manual';
                $actorAllowed = $isManual ? $this->actorRules->allows($edge['actor_rule'] ?? null, $actor, $workflowable) : null;

                return [
                    'edge_id' => $edge['id'],
                    'label' => ($edge['button_label'] ?? null) ?: ($edge['name'] ?? $edge['id']),
                    'trigger' => $edge['trigger'] ?? 'manual',
                    'to' => $edge['to'],
                    'preconditions_pass' => $preconditionsPass,
                    'actor_allowed' => $actorAllowed,
                    'available' => $preconditionsPass && $actorAllowed !== false,
                ];
            })
            ->values()
            ->all();

        return ['node' => $node, 'edges' => $edges];
    }

    /**
     * Simulates firing one edge from $nodeId. Reports actor_rule/precondition
     * failure as a normal (non-exceptional) result rather than throwing,
     * since "this wouldn't be allowed right now" is exactly what a dry run
     * exists to surface. On success, walks forward through any automatic
     * chain the destination triggers.
     *
     * @return array{ok: bool, error?: string, actions_preview?: array<int, string>, trail?: array<int, string>, resulting_nodes?: array<int, string>}
     */
    public function advance(
        WorkflowDefinitionVersion $version,
        Model $workflowable,
        ?Authenticatable $actor,
        string $nodeId,
        string $edgeId,
        array $inputs = [],
    ): array {
        $edge = $version->edge($edgeId);

        if (! $edge || $edge['from'] !== $nodeId) {
            return ['ok' => false, 'error' => "Edge [{$edgeId}] is not available from [{$nodeId}]."];
        }

        if (($edge['trigger'] ?? 'manual') === 'manual' && ! $this->actorRules->allows($edge['actor_rule'] ?? null, $actor, $workflowable)) {
            return ['ok' => false, 'error' => 'The selected actor is not permitted to trigger this transition.'];
        }

        if (! $this->preconditions->passes($edge['preconditions'] ?? null, $workflowable)) {
            return ['ok' => false, 'error' => "This transition's preconditions are not currently met."];
        }

        $actionsPreview = collect($edge['actions'] ?? [])
            ->map(fn (array $action) => $this->actions->resolve($action['type'])->preview($action, [
                'instance' => $this->fakeInstance($workflowable),
                'token' => null,
                'edge' => $edge,
                'actor' => $actor,
                'inputs' => $inputs,
            ]))
            ->values()
            ->all();

        [$resultingNodes, $trail] = $this->walkForward($version, $workflowable, $edge['to'], []);

        return [
            'ok' => true,
            'actions_preview' => $actionsPreview,
            'trail' => $trail,
            'resulting_nodes' => $resultingNodes,
        ];
    }

    /**
     * A minimal stand-in for the real WorkflowInstance a built-in action's
     * preview() reads `$context['instance']->workflowable()` off of (see
     * SendEmail/SendNotification) — a dry run has no real instance row to
     * hand it, since nothing here is ever persisted.
     */
    protected function fakeInstance(Model $workflowable): object
    {
        return new class($workflowable)
        {
            public function __construct(protected Model $workflowable)
            {
            }

            public function workflowable(): Model
            {
                return $this->workflowable;
            }
        };
    }

    /**
     * Follows automatic edges forward from $nodeId until landing on a node
     * with no passing automatic edge. A fork recurses down every branch
     * independently; a join is reported as a landing point rather than
     * guessing completion — a join only actually completes once every real,
     * persisted sibling token has arrived, which a stateless dry run has no
     * way to know.
     *
     * @return array{0: array<int, string>, 1: array<int, string>} [resultingNodeIds, trail]
     */
    protected function walkForward(WorkflowDefinitionVersion $version, Model $workflowable, string $nodeId, array $trail, int $depth = 0): array
    {
        $trail[] = $nodeId;

        // Guards against a cyclic chain of automatic edges looping forever.
        if ($depth > 20) {
            return [[$nodeId], $trail];
        }

        $node = $version->node($nodeId);

        if (! $node) {
            return [[$nodeId], $trail];
        }

        if ($node['type'] === 'fork') {
            $resulting = [];

            foreach ($version->edgesFrom($nodeId) as $edge) {
                [$branchResult, $trail] = $this->walkForward($version, $workflowable, $edge['to'], $trail, $depth + 1);
                $resulting = [...$resulting, ...$branchResult];
            }

            return [$resulting, $trail];
        }

        if ($node['type'] === 'join') {
            return [[$nodeId], $trail];
        }

        foreach ($version->edgesFrom($nodeId) as $edge) {
            if (($edge['trigger'] ?? 'manual') !== 'automatic') {
                continue;
            }

            if ($this->preconditions->passes($edge['preconditions'] ?? null, $workflowable)) {
                return $this->walkForward($version, $workflowable, $edge['to'], $trail, $depth + 1);
            }
        }

        return [[$nodeId], $trail];
    }
}
