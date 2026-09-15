<?php

namespace Workflow\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A minimal stand-in for Backpack's real $crud (CrudPanel) object, just
 * enough of its surface for crud::fields.{type} partials to render outside
 * a real CrudController operation — used by the show-workflow page to
 * render a node's non-readonly field_policy entries as genuinely editable
 * inputs. Only field types that touch $crud no further than
 * fieldTypeNotLoaded()/markFieldTypeAsLoaded()/getCurrentEntry() and
 * $crud->model (a real model instance, for things like ::isColumnNullable()
 * or ->translationEnabled()) are safe to render this way — see
 * WorkflowInlineFieldRenderer::EDITABLE_FIELD_TYPES.
 */
class InlineFieldCrudStub
{
    /** @var array<int, string> */
    protected array $loadedTypes = [];

    public function __construct(public Model $model)
    {
    }

    public function getCurrentEntry(): Model
    {
        return $this->model;
    }

    public function fieldTypeNotLoaded($field): bool
    {
        return ! in_array($this->typeOf($field), $this->loadedTypes, true);
    }

    public function markFieldTypeAsLoaded($field): void
    {
        $this->loadedTypes[] = $this->typeOf($field);
    }

    public function checkIfFieldIsFirstOfItsType($field): bool
    {
        return $this->fieldTypeNotLoaded($field);
    }

    protected function typeOf($field): string
    {
        return is_array($field) ? ($field['type'] ?? '') : (string) $field;
    }
}
