<?php

namespace Workflow\Preconditions;

use Illuminate\Database\Eloquent\Model;
use Workflow\Registries\WorkflowPreconditionType;

class FieldIn implements WorkflowPreconditionType
{
    public function passes(array $params, Model $model): bool
    {
        return in_array(data_get($model, $params['field']), $params['values'] ?? [], true);
    }
}
