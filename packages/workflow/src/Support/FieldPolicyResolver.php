<?php

namespace Workflow\Support;

use Workflow\Models\WorkflowInstance;

/**
 * Applies a node's field_policy — an ordered list of {field, mode} where
 * mode is edit|readonly|hidden — to a Backpack fields array, before the
 * CrudController hands it to CRUD::addFields(). A CrudController calls this
 * from its own setupUpdateOperation(); it's plain PHP, not a Backpack field/
 * column type, so it needs no app-level view file.
 */
class FieldPolicyResolver
{
    /**
     * @param  array<int, array<string, mixed>>  $fields  Backpack field definitions, keyed numerically.
     * @return array<int, array<string, mixed>> The same fields, reordered/marked per the node's policy.
     */
    public function apply(array $fields, ?WorkflowInstance $instance): array
    {
        if (! $instance) {
            return $fields;
        }

        $version = $instance->version;
        $token = $instance->activeTokens()->first();

        if (! $token) {
            return $fields;
        }

        $node = $version->node($token->node_id);
        $policy = $node['field_policy'] ?? [];

        if (empty($policy)) {
            return $fields;
        }

        return $this->reorderAndMark($fields, $policy);
    }

    /**
     * @param  array<int, array<string, mixed>>  $policy  Ordered list of {field, mode}.
     */
    protected function reorderAndMark(array $fields, array $policy): array
    {
        $default = config('workflow.default_field_mode', 'readonly');
        $byName = collect($fields)->keyBy('name');
        $policyByName = collect($policy)->keyBy('field');

        $ordered = collect($policy)
            ->map(fn (array $entry) => $byName->get($entry['field']))
            ->filter()
            ->values();

        $remaining = $byName->reject(fn ($field, $name) => $policyByName->has($name))->values();

        return $ordered->merge($remaining)
            ->map(function (array $field) use ($policyByName, $default) {
                $mode = $policyByName->get($field['name'])['mode'] ?? $default;

                return match ($mode) {
                    'hidden' => null,
                    'readonly' => $field + ['attributes' => ($field['attributes'] ?? []) + ['disabled' => 'disabled']],
                    default => $field,
                };
            })
            ->filter()
            ->values()
            ->all();
    }
}
