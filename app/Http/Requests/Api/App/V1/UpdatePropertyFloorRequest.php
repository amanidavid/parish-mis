<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePropertyFloorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_uuid' => ['sometimes', 'uuid'],
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'floor_number' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
