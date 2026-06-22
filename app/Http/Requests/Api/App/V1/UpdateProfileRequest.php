<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine whether the user can make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'username' => ['sometimes', 'nullable', 'string', 'min:3', 'max:50', 'alpha_dash'],
            'name' => ['sometimes', 'string', 'min:3', 'max:100'],
            'country_code' => ['sometimes', 'nullable', 'string', 'min:1', 'max:10'],
            'phone' => ['sometimes', 'string', 'min:6', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
        ];
    }
}

