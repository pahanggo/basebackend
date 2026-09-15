<?php

namespace Workflow\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Workflow\Models\WorkflowInstance;

/**
 * Splits a node's field_policy into "read-only display" vs. "genuinely
 * editable right here on the show-workflow page" — the latter is how a
 * field marked not-readonly gets edited without ever needing the model's
 * own Backpack Update operation enabled or its Edit button shown (see
 * WorkflowInlineUpdateController). Authorization for editing a field this
 * way is exactly its field_policy readonly flag: whoever can already reach
 * this node's show-workflow page (same posture as the page itself — no
 * extra actor_rule layer) can edit whatever that node marks editable.
 * Deliberately decoupled from operation_settings/row_actions.update, which
 * gate the model's real Backpack Update operation instead.
 */
class WorkflowInlineFieldRenderer
{
    /**
     * Field types safe to render via crud::fields.{type} through
     * InlineFieldCrudStub — verified to touch $crud no further than
     * fieldTypeNotLoaded()/markFieldTypeAsLoaded()/getCurrentEntry() and
     * $crud->model. Anything else falls back to a plain text input rather
     * than risk a hard error against relation-only APIs the stub doesn't
     * implement (getRelationModel(), etc).
     */
    public const EDITABLE_FIELD_TYPES = [
        'text', 'textarea', 'email', 'url', 'password', 'hidden', 'number',
        'checkbox', 'boolean', 'switch', 'radio', 'enum',
        'money', 'phone', 'identity', 'slug',
        'date', 'date_only', 'date_picker', 'datetime', 'datetime_picker',
        'time', 'time_range', 'date_range', 'month', 'week',
        'select_from_array',
        'color', 'color_picker', 'icon_picker',
        'address_google', 'latlng_picker',
        'ckeditor', 'tinymce', 'summernote', 'easymde', 'simplemde',
        'upload', 'upload_multiple', 'ajax_upload', 'ajax_multi_upload', 'image', 'base64_image', 'video',
        'tags',
    ];

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     *   Built field/column definitions, in the node's own field_policy
     *   order, each additionally carrying an 'editable' flag; an editable
     *   entry also carries a resolved 'value' and 'render_type' (clamped to
     *   EDITABLE_FIELD_TYPES, falling back to 'text').
     */
    public function split(array $node, Model $workflowable): \Illuminate\Support\Collection
    {
        $default = config('workflow.default_field_mode', 'readonly');
        $resolver = app(FieldPolicyResolver::class);

        return collect($node['field_policy'] ?? [])
            ->filter(fn (array $entry) => ($entry['visible'] ?? true) !== false)
            ->map(function (array $entry) use ($resolver, $default, $workflowable) {
                $built = $this->buildColumn($entry);
                $mode = $resolver->resolveMode($entry, $default);

                // A dotted field name (a related model's own column,
                // surfaced via the field-policy editor's model discovery)
                // is never offered as inline-editable — resolving and
                // mass-assigning a relation's own attribute safely is a
                // different, unsupported problem here. It still displays,
                // just never as an input.
                $built['editable'] = $mode === 'edit' && ! str_contains($entry['field'], '.');

                if ($built['editable']) {
                    $built['render_type'] = in_array($built['type'], self::EDITABLE_FIELD_TYPES, true) ? $built['type'] : 'text';
                    $built['value'] = $workflowable->getAttribute($entry['field']);
                }

                return $built;
            })
            ->values();
    }

    /**
     * The set of field names this node's field_policy currently allows
     * editing inline — used by WorkflowInlineUpdateController to filter
     * which submitted values are actually allowed to be saved. Recomputed
     * from the SAME node the show page just rendered, never trusted from
     * client input.
     */
    public function editableFieldNames(array $node): array
    {
        $default = config('workflow.default_field_mode', 'readonly');
        $resolver = app(FieldPolicyResolver::class);

        return collect($node['field_policy'] ?? [])
            ->filter(fn (array $entry) => ($entry['visible'] ?? true) !== false)
            ->filter(fn (array $entry) => ! str_contains($entry['field'], '.'))
            ->filter(fn (array $entry) => $resolver->resolveMode($entry, $default) === 'edit')
            ->keyBy('field')
            ->all();
    }

    public function currentNode(?WorkflowInstance $instance): ?array
    {
        if (! $instance) {
            return null;
        }

        $token = $instance->activeTokens()->first();

        return $token ? $instance->effectiveVersion()->node($token->node_id) : null;
    }

    protected function buildColumn(array $entry): array
    {
        $column = [
            'name' => $entry['field'],
            'label' => ($entry['label'] ?? null) ?: Str::headline(str_replace('.', ' ', $entry['field'])),
            'type' => $entry['type'] ?? 'text',
        ];

        if (! empty($entry['custom_field_definition'])) {
            $decoded = (new CustomFieldDefinitionParser)->parse((string) $entry['custom_field_definition']);

            if (is_array($decoded)) {
                $column = array_merge($column, $decoded);
            }
        }

        return $column;
    }
}
