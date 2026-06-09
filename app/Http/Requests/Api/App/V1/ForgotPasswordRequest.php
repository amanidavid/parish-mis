<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'country_code' => ['nullable', 'required_with:phone', 'string', 'min:1', 'max:10'],
            'phone' => ['nullable', 'required_without:email', 'string'],
            'email' => ['nullable', 'required_without:phone', 'email'],
        ];
    }
}
