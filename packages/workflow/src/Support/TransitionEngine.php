<?php

namespace Workflow\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;
use Workflow\Events\TransitionedIn;
use Workflow\Events\TransitionedOut;
use Workflow\Events\TransitioningIn;
use Workflow\Events\TransitioningOut;
use Workflow\Models\WorkflowDefinition;
use Workflow\Models\WorkflowDefinitionVersion;
use Workflow\Models\WorkflowInstance;
use Workflow\Models\WorkflowInstanceHistory;
use Workflow\Models\WorkflowInstancePendingActor;
use Workflow\Models\WorkflowInstanceToken;
use Workflow\Registries\WorkflowActionRegistry;

/**
 * The token engine: starts instances, advances tokens along edges (including
 * fork/join and automatic chains), enforces actor_rule/preconditions, records
 * history, fires actions, and dispatches events. This is the one place that
 * understands the graph's execution semantics.
 *
 * Every edge firing dispatches TransitioningOut (cancellable) then
 * TransitionedOut around the departure, and TransitioningIn/TransitionedIn
 * around each resulting arrival — including once per fork branch and once
 * for a completed join — so a listener can hook either "leaving a node" or
 * "arriving at a node" independently of which edge caused it.
 */
class TransitionEngine
{
    public function __construct(
        protected PreconditionEvaluator $preconditions,
        protected ActorRuleResolver $actorRules,
        protected WorkflowActionRegistry $actions,
    ) {
    }

    public function start(Model $workflowable, WorkflowDefinition $definition): WorkflowInstance
    {
        $version = $definition->publishedVersion;

        if (! $version) {
            throw new RuntimeException("Workflow definition [{$definition->slug}] has no published version.");
        }

        $startNodeId = $version->graph['start'] ?? null;

        if (! $startNodeId) {
            throw new RuntimeException('Workflow definition version has no declared start node.');
        }

        $instance = WorkflowInstance::create([
            'workflow_definition_id' => $definition->id,
            'workflow_definition_version_id' => $version->id,
            'workflowable_type' => $workflowable::class,
            'workflowable_id' => $workflowable->getKey(),
            'status' => 'active',
        ]);

        $this->arriveAt($instance, $version, $startNodeId, null, null, null, []);

        return $instance;
    }

    /**
     * Transitions a token available to the given actor. Preconditions always
     * run; actor_rule is skipped when $actor is null and $skipActorCheck is
     * true (the "system" trigger case for programmatic/automatic/timer/webhook
     * transitions).
     */
    public function transition(
        WorkflowInstanceToken $token,
        string $edgeId,
        array $inputs = [],
        ?Authenticatable $actor = null,
        bool $skipActorCheck = false,
    ): ?WorkflowInstanceHistory {
        $instance = $token->instance;
        $version = $instance->version;
        $edge = $version->edge($edgeId);

        if (! $edge || $edge['from'] !== $token->node_id) {
            throw new RuntimeException("Edge [{$edgeId}] is not available from token's current node.");
        }

        $workflowable = $instance->workflowable();

        if (! $workflowable) {
            throw new RuntimeException('Workflow instance has no resolvable workflowable model.');
        }

        if (! $skipActorCheck && ! $this->actorRules->allows($edge['actor_rule'] ?? null, $actor)) {
            return null;
        }

        if (! $this->preconditions->passes($edge['preconditions'] ?? null, $workflowable)) {
            return null;
        }

        $out = new TransitioningOut($instance, $token, $edge, $workflowable, $actor, $inputs);
        event($out);

        if ($out->isCancelled()) {
            return null;
        }

        $token->consume();

        $history = WorkflowInstanceHistory::create([
            'workflow_instance_id' => $instance->id,
            'workflow_instance_token_id' => $token->id,
            'from_node_id' => $edge['from'],
            'to_node_id' => $edge['to'],
            'edge_id' => $edge['id'],
            'trigger' => $edge['trigger'] ?? 'manual',
            'actor_type' => $actor ? $actor::class : null,
            'actor_id' => $actor?->getAuthIdentifier(),
            'inputs' => $inputs,
            'created_at' => now(),
        ]);

        foreach ($edge['actions'] ?? [] as $action) {
            $this->actions->resolve($action['type'])->execute($action, [
                'instance' => $instance,
                'token' => $token,
                'edge' => $edge,
                'actor' => $actor,
                'inputs' => $inputs,
            ]);
        }

        TransitionedOut::dispatch($instance, $token, $edge, $workflowable, $history, $actor, $inputs);

        $this->advanceInto($instance, $version, $edge['to'], $token->fork_group_id, $edge, $actor, $inputs);

        return $history;
    }

    /**
     * Lands the instance on $nodeId, handling fork/join/automatic chains.
     */
    protected function advanceInto(
        WorkflowInstance $instance,
        WorkflowDefinitionVersion $version,
        string $nodeId,
        ?string $forkGroupId,
        ?array $causingEdge,
        ?Authenticatable $actor,
        array $inputs,
    ): void {
        $node = $version->node($nodeId);

        if (! $node) {
            throw new RuntimeException("Unknown node [{$nodeId}] in workflow graph.");
        }

        if ($node['type'] === 'fork') {
            $forkGroupId = (string) Str::uuid();

            foreach ($version->edgesFrom($nodeId) as $edge) {
                $this->arriveAt($instance, $version, $edge['to'], $forkGroupId, $edge, $actor, $inputs);
            }

            return;
        }

        if ($node['type'] === 'join') {
            $expected = $version->incomingEdgeCount($nodeId);
            $arrived = $instance->tokens()
                ->where('node_id', $nodeId)
                ->where('status', 'active')
                ->where('fork_group_id', $forkGroupId)
                ->get();

            $complete = $arrived->count() + 1 >= $expected;

            $workflowable = $instance->workflowable();
            event(new TransitioningIn($instance, $nodeId, $causingEdge, $workflowable, $actor, $inputs));

            $token = $instance->tokens()->create([
                'node_id' => $nodeId,
                'status' => $complete ? 'consumed' : 'active',
                'fork_group_id' => $forkGroupId,
                'consumed_at' => $complete ? now() : null,
            ]);

            TransitionedIn::dispatch($instance, $token, $causingEdge, $workflowable, $actor, $inputs);

            if (! $complete) {
                // Still waiting on sibling branches — nothing more to do yet.
                return;
            }

            $arrived->each->consume();

            $onward = $version->edgesFrom($nodeId)[0] ?? null;

            if ($onward) {
                $this->arriveAt($instance, $version, $onward['to'], null, $onward, null, []);
            }

            return;
        }

        $this->arriveAt($instance, $version, $nodeId, $forkGroupId, $causingEdge, $actor, $inputs);
    }

    /**
     * Creates the active token for a plain state-node arrival (used by
     * start(), a normal transition's destination, and each fork branch),
     * wrapped in the TransitioningIn/TransitionedIn events, then checks for
     * an automatic continuation.
     */
    protected function arriveAt(
        WorkflowInstance $instance,
        WorkflowDefinitionVersion $version,
        string $nodeId,
        ?string $forkGroupId,
        ?array $causingEdge,
        ?Authenticatable $actor,
        array $inputs,
    ): void {
        $workflowable = $instance->workflowable();

        event(new TransitioningIn($instance, $nodeId, $causingEdge, $workflowable, $actor, $inputs));

        $token = $instance->tokens()->create([
            'node_id' => $nodeId,
            'status' => 'active',
            'fork_group_id' => $forkGroupId,
        ]);

        $this->refreshPendingActors($instance, $token, $version);

        TransitionedIn::dispatch($instance, $token, $causingEdge, $workflowable, $actor, $inputs);

        $this->maybeAutoAdvance($instance, $version, $token);
    }

    /**
     * If the node the token just landed on has an `automatic` outgoing edge
     * whose precondition already passes, fire it immediately (no human
     * click involved) — this is also how conditional branching is expressed:
     * several automatic edges from one node, each with a different precondition.
     */
    protected function maybeAutoAdvance(WorkflowInstance $instance, WorkflowDefinitionVersion $version, WorkflowInstanceToken $token): void
    {
        $workflowable = $instance->workflowable();

        foreach ($version->edgesFrom($token->node_id) as $edge) {
            if (($edge['trigger'] ?? 'manual') !== 'automatic') {
                continue;
            }

            if ($this->preconditions->passes($edge['preconditions'] ?? null, $workflowable)) {
                $this->transition($token, $edge['id'], [], null, true);

                return;
            }
        }
    }

    /**
     * Recomputes workflow_instance_pending_actors for a token's manual
     * outgoing edges, so the "My Tasks" widget query stays cheap and correct.
     */
    protected function refreshPendingActors(WorkflowInstance $instance, WorkflowInstanceToken $token, WorkflowDefinitionVersion $version): void
    {
        WorkflowInstancePendingActor::where('workflow_instance_token_id', $token->id)->delete();

        foreach ($version->edgesFrom($token->node_id) as $edge) {
            if (($edge['trigger'] ?? 'manual') !== 'manual') {
                continue;
            }

            foreach ($this->actorRules->pendingActorRows($edge['actor_rule'] ?? null) as $row) {
                WorkflowInstancePendingActor::create([
                    'workflow_instance_id' => $instance->id,
                    'workflow_instance_token_id' => $token->id,
                    'edge_id' => $edge['id'],
                    ...$row,
                ]);
            }
        }
    }
}
