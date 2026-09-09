<?php

namespace Workflow\Preconditions;

use Illuminate\Database\Eloquent\Model;
use Workflow\Registries\WorkflowPreconditionType;

/**
 * Numeric/string comparison beyond simple equality — e.g. the Purchase
 * Request demo's "amount <= remaining budget" check on a manual edge.
 */
class FieldCompare implements WorkflowPreconditionType
{
    public function passes(array $params, Model $model): bool
    {
        $left = data_get($model, $params['field']);
        $right = array_key_exists('value', $params) ? $params['value'] : data_get($model, $params['value_field']);

        return match ($params['operator'] ?? 'eq') {
            'eq' => $left == $right,
            'ne' => $left != $right,
            'lt' => $left < $right,
            'lte' => $left <= $right,
            'gt' => $left > $right,
            'gte' => $left >= $right,
            default => false,
        };
    }
}
