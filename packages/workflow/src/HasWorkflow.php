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
    /**
     * Per-class override of config('workflow.enable_versioning'). null (the
     * default) means "defer to config" — each class using this trait gets
     * its own copy of this static property, so overriding it on one
     * HasWorkflow model never affects another.
     */
    protected static ?bool $workflowVersioningEnabled = null;

    abstract public function workflowDefinitionSlug(): string;

    /**
     * Enables (the default) or disables versioning for this model. With
     * versioning disabled, every instance of this model — in-flight ones
     * included, with no data migration needed — always runs against its
     * definition's current published version instead of staying pinned to
     * whichever version it started on (see
     * WorkflowInstance::effectiveVersion()).
     */
    public static function setEnableVersioning(bool $enabled = true): void
    {
        static::$workflowVersioningEnabled = $enabled;
    }

    public static function versioningEnabled(): bool
    {
        return static::$workflowVersioningEnabled ?? config('workflow.enable_versioning', true);
    }

    /**
     * Laravel's trait-boot convention: Eloquent calls bootHasWorkflow()
     * automatically for any model using this trait. Starting the workflow
     * the moment a record is created means a downstream CrudController (or
     * any other creation path — a job, a seeder, tinker) gets this for free,
     * without remembering to call startWorkflow() itself.
     */
    public static function bootHasWorkflow(): void
    {
        static::created(function (self $model) {
            $model->startWorkflow();
        });
    }

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
            $version = $instance->effectiveVersion();
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

        $version = $instance->effectiveVersion();

        return $instance->activeTokens
            ->flatMap(fn ($token) => $version->edgesFrom($token->node_id))
            ->values()
            ->all();
    }
}
