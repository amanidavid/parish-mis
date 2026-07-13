<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class GoogleRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'credential' => ['required', 'string', 'min:20', 'max:4096'],
            'username' => ['nullable', 'string', 'min:3', 'max:50', 'alpha_dash'],
            'name' => ['nullable', 'string', 'min:3', 'max:100'],
            'country_code' => ['required', 'string', 'min:1', 'max:10'],
            'phone' => ['required', 'string', 'min:6', 'max:30'],
        ];
    }
}
