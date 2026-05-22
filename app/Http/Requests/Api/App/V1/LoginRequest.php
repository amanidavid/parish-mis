<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'workspace_uuid' => ['nullable', 'uuid'],
            'phone' => ['nullable', 'required_without:email', 'string'],
            'email' => ['nullable', 'required_without:phone', 'email'],
            'password' => ['required', 'string', 'min:6'],
        ];
    }
}
