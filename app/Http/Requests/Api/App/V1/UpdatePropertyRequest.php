<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'type_uuid' => ['sometimes', 'nullable', 'uuid'],
            'district_uuid' => ['sometimes', 'nullable', 'uuid'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
