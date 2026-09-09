<?php

namespace Workflow\Preconditions;

use Illuminate\Database\Eloquent\Model;
use Workflow\Registries\WorkflowPreconditionType;

class FieldEquals implements WorkflowPreconditionType
{
    public function passes(array $params, Model $model): bool
    {
        return data_get($model, $params['field']) == $params['value'];
    }
}
