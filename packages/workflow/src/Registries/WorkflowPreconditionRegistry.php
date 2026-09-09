<?php

namespace Workflow\Registries;

use InvalidArgumentException;

class WorkflowPreconditionRegistry
{
    /** @var array<string, class-string<WorkflowPreconditionType>> */
    protected array $types = [];

    public function register(string $key, string $class): void
    {
        $this->types[$key] = $class;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function resolve(string $key): WorkflowPreconditionType
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("No workflow precondition type registered for key [{$key}].");
        }

        return app($this->types[$key]);
    }

    /** @return array<string> */
    public function keys(): array
    {
        return array_keys($this->types);
    }
}
