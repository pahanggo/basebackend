<?php

namespace Workflow;

use Illuminate\Contracts\Auth\Authenticatable;
use Workflow\Models\WorkflowDefinition;
use Workflow\Models\WorkflowInstance;

/**
 * Add to any Eloquent model to opt it into the workflow engine. The model
 * must define workflowDefinitionSlug() to say which definition governs it.
 */
trait HasWorkflow
{
    abstract public function workflowDefinitionSlug(): string;

    public function workflowInstance(): ?WorkflowInstance
    {
        return WorkflowInstance::query()
            ->where('workflowable_type', static::class)
            ->where('workflowable_id', $this->getKey())
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }

    public function startWorkflow(): WorkflowInstance
    {
        $definition = WorkflowDefinition::where('slug', $this->workflowDefinitionSlug())->firstOrFail();

        return app(Support\TransitionEngine::class)->start($this, $definition);
    }

    /**
     * First-class programmatic API — callable from a controller, job, or
     * service, not just from a button. Pass $actor to enforce that edge's
     * actor_rule against them; omit it to run as "system" (actor_rule
     * skipped, preconditions still enforced).
     */
    public function transitionTo(string $edgeId, array $inputs = [], ?Authenticatable $actor = null): bool
    {
        $instance = $this->workflowInstance();

        if (! $instance) {
            return false;
        }

        $engine = app(Support\TransitionEngine::class);

        foreach ($instance->activeTokens as $token) {
            $version = $instance->version;
            $edge = $version->edge($edgeId);

            if ($edge && $edge['from'] === $token->node_id) {
                return (bool) $engine->transition($token, $edgeId, $inputs, $actor, $actor === null);
            }
        }

        return false;
    }

    /**
     * @return array<int, array> Edges available right now, from any active
     *                           token, regardless of actor — used for UI rendering
     *                           where availability per-viewer is checked separately.
     */
    public function availableTransitions(): array
    {
        $instance = $this->workflowInstance();

        if (! $instance) {
            return [];
        }

        $version = $instance->version;

        return $instance->activeTokens
            ->flatMap(fn ($token) => $version->edgesFrom($token->node_id))
            ->values()
            ->all();
    }
}
