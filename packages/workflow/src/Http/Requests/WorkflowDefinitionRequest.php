<?php

namespace Workflow\Http\Requests;

use Backpack\CRUD\app\Http\Requests\CrudRequest;

class WorkflowDefinitionRequest extends CrudRequest
{
    public function authorize(): bool
    {
        return backpack_auth()->check();
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'required|alpha_dash|max:255|unique:workflow.workflow_definitions,slug,'.$this->route('id'),
            'model' => 'required|string|max:255',
            'description' => 'nullable|string',
        ];
    }
}
