<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'regex:/^[a-z0-9_]+$/', 'max:120'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'min:1'],
        ];
    }
}
