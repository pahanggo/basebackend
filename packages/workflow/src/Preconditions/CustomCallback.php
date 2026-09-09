<?php

namespace Workflow\Preconditions;

use Illuminate\Database\Eloquent\Model;
use Workflow\Registries\WorkflowPreconditionType;

/**
 * Escape hatch: references a callback class the downstream developer has
 * already written and registered in their own app code — never freeform
 * code typed into the editor. See "Rule & actor authoring UX" in the plan.
 */
class CustomCallback implements WorkflowPreconditionType
{
    public function passes(array $params, Model $model): bool
    {
        $class = $params['class'];

        return app($class)->passes($params['params'] ?? [], $model);
    }
}
