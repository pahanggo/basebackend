<?php

namespace WorkflowDemo\PurchaseRequest\Http\Requests;

use Backpack\CRUD\app\Http\Requests\CrudRequest;

class PurchaseRequestFormRequest extends CrudRequest
{
    public function authorize(): bool
    {
        return backpack_auth()->check();
    }

    public function rules(): array
    {
        return [
            'purpose' => 'required|string',
            'amount' => 'required|numeric|min:0',
        ];
    }
}
