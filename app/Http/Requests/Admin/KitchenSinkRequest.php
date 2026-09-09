<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class KitchenSinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return backpack_auth()->check();
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|min:2|max:255',
            'email' => 'nullable|email',
            'website' => 'nullable|url',
            'price' => 'nullable|numeric|min:0',
            'rating' => 'nullable|integer|between:0,10',
        ];
    }
}
