<?php

namespace Workflow\Registries;

use InvalidArgumentException;

/**
 * String-keyed registry of action types, resolved from graph JSON — the same
 * pattern Backpack uses to resolve field/column types by string key.
 */
class WorkflowActionRegistry
{
    /** @var array<string, class-string<WorkflowActionType>> */
    protected array $types = [];

    public function register(string $key, string $class): void
    {
        $this->types[$key] = $class;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function resolve(string $key): WorkflowActionType
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("No workflow action type registered for key [{$key}].");
        }

        return app($this->types[$key]);
    }

    /** @return array<string> */
    public function keys(): array
    {
        return array_keys($this->types);
    }
}
