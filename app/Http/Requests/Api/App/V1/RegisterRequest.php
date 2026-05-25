<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['nullable', 'string', 'min:3', 'max:50', 'alpha_dash'],
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'country_code' => ['required', 'string', 'min:1', 'max:10'],
            'phone' => ['required', 'string', 'min:6', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'password' => ['required', 'string', 'min:6'],
        ];
    }
}
