<?php

namespace Workflow\Preconditions;

use Illuminate\Database\Eloquent\Model;
use Workflow\Registries\WorkflowPreconditionType;

/**
 * Escape hatch: calls a public method already defined on the workflowable
 * model itself — never freeform code typed into the editor. The editor only
 * ever lets a designer pick from methods the model actually declares (named
 * `callbackFunction*`, via WorkflowModelCallbackSearchController), so this
 * can't be pointed at arbitrary code.
 */
class ModelCallback implements WorkflowPreconditionType
{
    public function passes(array $params, Model $model): bool
    {
        $method = $params['method'] ?? null;

        if (! $method || ! method_exists($model, $method)) {
            return false;
        }

        return (bool) $model->{$method}();
    }
}
