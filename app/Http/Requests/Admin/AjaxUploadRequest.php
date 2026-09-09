<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AjaxUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return backpack_auth()->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('ajax_upload.max_size_kb');
        $requested = (int) $this->input('max_size', $maxKb);

        return [
            'file' => ['required', 'file', 'max:'.($requested > 0 ? min($requested, $maxKb) : $maxKb)],
            'disk' => ['nullable', 'string', Rule::in(config('ajax_upload.disks'))],
            'path' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-\/]*$/'],
            'max_size' => ['nullable', 'integer'],
        ];
    }
}
