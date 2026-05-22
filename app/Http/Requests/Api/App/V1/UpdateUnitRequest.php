<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_floor_uuid' => ['sometimes', 'uuid'],
            'unit_number' => ['sometimes', 'string', 'min:1', 'max:120'],
            'status' => ['sometimes', 'in:vacant,occupied,maintenance'],
        ];
    }
}
