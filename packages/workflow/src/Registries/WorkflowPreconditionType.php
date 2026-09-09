<?php

namespace Workflow\Registries;

use Illuminate\Database\Eloquent\Model;

interface WorkflowPreconditionType
{
    /**
     * @param  array<string, mixed>  $params  The precondition leaf's own params (minus "type").
     */
    public function passes(array $params, Model $model): bool;
}
