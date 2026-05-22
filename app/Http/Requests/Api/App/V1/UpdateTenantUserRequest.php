<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:3', 'max:100'],
            'phone' => ['sometimes', 'string', 'min:6', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'password' => ['sometimes', 'nullable', 'string', 'min:6'],
            'status' => ['sometimes', 'in:active,suspended'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', 'max:120'],
        ];
    }
}
