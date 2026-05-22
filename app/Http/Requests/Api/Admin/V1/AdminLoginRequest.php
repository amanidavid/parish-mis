<?php

namespace App\Http\Requests\Api\Admin\V1;

use Illuminate\Foundation\Http\FormRequest;

class AdminLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['nullable', 'required_without:email', 'string'],
            'email' => ['nullable', 'required_without:username', 'email'],
            'password' => ['required', 'string', 'min:6'],
        ];
    }
}
