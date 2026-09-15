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

        $version = $instance->effectiveVersion();
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
     * @param  array<int, array<string, mixed>>  $policy  Ordered list of field-policy entries. Each is
     *                                                     either the legacy {field, mode} shape or the
     *                                                     designer's field-policy-editor shape
     *                                                     {field, visible, readonly, label, type, default,
     *                                                     section, field_definition}, where field_definition
     *                                                     is a raw JSON string merged in last.
     */
    protected function reorderAndMark(array $fields, array $policy): array
    {
        $default = config('workflow.default_field_mode', 'readonly');
        $byName = collect($fields)->keyBy('name');
        $policyByName = collect($policy)->keyBy('field');

        // A policy entry not already present in $fields (e.g. a field on a
        // related model, surfaced only via the field-policy editor's model
        // discovery) still gets a row here, built from the entry itself
        // rather than being silently dropped.
        $ordered = collect($policy)
            ->map(fn (array $entry) => $byName->get($entry['field']) ?? ['name' => $entry['field'], 'type' => $entry['type'] ?? 'text']);

        $remaining = $byName->reject(fn ($field, $name) => $policyByName->has($name))->values();

        return $ordered->merge($remaining)
            ->map(function (array $field) use ($policyByName, $default) {
                $entry = $policyByName->get($field['name']);
                $mode = $entry ? $this->resolveMode($entry, $default) : $default;

                if ($mode === 'hidden') {
                    return null;
                }

                if ($entry) {
                    $field = $this->applyOverrides($field, $entry);
                }

                if ($mode === 'readonly') {
                    $field['attributes'] = ($field['attributes'] ?? []) + ['disabled' => 'disabled'];
                }

                return $field;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function resolveMode(array $entry, string $default): string
    {
        if (isset($entry['mode'])) {
            return $entry['mode'];
        }

        if (($entry['visible'] ?? true) === false) {
            return 'hidden';
        }

        return ($entry['readonly'] ?? true) ? 'readonly' : 'edit';
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected function applyOverrides(array $field, array $entry): array
    {
        if (! empty($entry['label'])) {
            $field['label'] = $entry['label'];
        }

        if (! empty($entry['type'])) {
            $field['type'] = $entry['type'];
        }

        if (array_key_exists('default', $entry) && $entry['default'] !== '') {
            $field['default'] = $entry['default'];
        }

        if (! empty($entry['section'])) {
            $field['section'] = $entry['section'];
        }

        // Legacy: the field-policy editor used to have dedicated "options"
        // (a "value:Label" per line textarea) and "tab" columns, before
        // field_definition replaced them as a single JSON escape hatch —
        // still honored here so a graph saved before that change doesn't
        // lose them.
        if (! empty($entry['options'])) {
            $field['options'] = is_string($entry['options'])
                ? $this->parseOptions($entry['options'])
                : $entry['options'];
        }

        if (! empty($entry['tab'])) {
            $field['tab'] = $entry['tab'];
        }

        // Legacy: the field-policy editor's first escape hatch was raw JSON
        // (field_definition), before custom_field_definition replaced it
        // with PHP array syntax — still honored so a graph saved before
        // that change doesn't lose it.
        if (! empty($entry['field_definition'])) {
            $decoded = json_decode((string) $entry['field_definition'], true);

            if (is_array($decoded)) {
                $field = array_merge($field, $decoded);
            }
        }

        // Escape hatch: merges (and can override) any Backpack field
        // attribute the simple columns above don't cover — options, tab,
        // wrapper, attributes, validationRules, etc. Written as a literal
        // PHP array (matching how a developer would write this same config
        // by hand), never as freeform executable code — see
        // CustomFieldDefinitionParser for how eval()-ing it stays safe.
        // Highest precedence, applied last.
        if (! empty($entry['custom_field_definition'])) {
            $decoded = (new CustomFieldDefinitionParser)->parse((string) $entry['custom_field_definition']);

            if (is_array($decoded)) {
                $field = array_merge($field, $decoded);
            }
        }

        return $field;
    }

    /**
     * Parses the field-policy editor's options textarea: one option per
     * line, either "value:Label" or a bare value used as its own label.
     *
     * @return array<string, string>
     */
    protected function parseOptions(string $raw): array
    {
        $options = [];

        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (str_contains($line, ':')) {
                [$key, $label] = explode(':', $line, 2);
                $options[trim($key)] = trim($label);
            } else {
                $options[$line] = $line;
            }
        }

        return $options;
    }
}
