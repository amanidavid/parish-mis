<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class UnitIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'property_uuid' => ['nullable', 'uuid'],
            'property_floor_uuid' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:vacant,occupied,maintenance'],
            'unit_number' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
