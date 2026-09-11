<?php

namespace Workflow\Actions;

use Workflow\Registries\WorkflowActionType;

/**
 * Escape hatch: calls a public method already defined on the workflowable
 * model itself — never freeform code typed into the editor. The editor only
 * ever lets a designer pick from methods the model actually declares (named
 * `callbackFunction*`, via WorkflowModelCallbackSearchController), so this
 * can't be pointed at arbitrary code.
 */
class ModelCallback implements WorkflowActionType
{
    public function execute(array $params, array $context): void
    {
        $model = $context['instance']?->workflowable();
        $method = $params['method'] ?? null;

        if ($model && $method && method_exists($model, $method)) {
            $model->{$method}($context);
        }
    }

    public function preview(array $params, array $context): string
    {
        return sprintf('Would call %s() on the workflowable model', $params['method'] ?? '(no method set)');
    }
}
