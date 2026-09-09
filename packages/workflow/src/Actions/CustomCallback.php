<?php

namespace Workflow\Actions;

use Workflow\Registries\WorkflowActionType;

/**
 * Escape hatch: references an action class the downstream developer has
 * already written and registered — never freeform code typed into the
 * editor. See "Rule & actor authoring UX" in the plan.
 */
class CustomCallback implements WorkflowActionType
{
    public function execute(array $params, array $context): void
    {
        app($params['class'])->execute($params['params'] ?? [], $context);
    }

    public function preview(array $params, array $context): string
    {
        $instance = app($params['class']);

        return method_exists($instance, 'preview')
            ? $instance->preview($params['params'] ?? [], $context)
            : sprintf('Would run custom callback %s', $params['class']);
    }
}
