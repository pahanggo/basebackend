<?php

namespace Workflow\Support;

use Illuminate\Database\Eloquent\Model;
use Workflow\Registries\WorkflowPreconditionRegistry;

/**
 * Evaluates a precondition tree: {op: 'and'|'or', children: [...]} nesting
 * down to leaves like {type: 'field_equals', field: 'status', value: '...'}.
 * An empty/missing tree always passes (an edge with no preconditions is
 * always available).
 */
class PreconditionEvaluator
{
    public function __construct(protected WorkflowPreconditionRegistry $registry)
    {
    }

    /**
     * @param  array<string, mixed>|null  $tree
     */
    public function passes(?array $tree, Model $model): bool
    {
        if (empty($tree)) {
            return true;
        }

        // A leaf node has a "type" key; a group node has "op"/"children".
        if (isset($tree['type'])) {
            $params = $tree;
            unset($params['type']);

            return $this->registry->resolve($tree['type'])->passes($params, $model);
        }

        $op = $tree['op'] ?? 'and';
        $children = $tree['children'] ?? [];

        if ($op === 'or') {
            foreach ($children as $child) {
                if ($this->passes($child, $model)) {
                    return true;
                }
            }

            return empty($children);
        }

        // Default: 'and'
        foreach ($children as $child) {
            if (! $this->passes($child, $model)) {
                return false;
            }
        }

        return true;
    }
}
