<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class StorePropertyFloorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_uuid' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'floor_number' => ['required', 'integer', 'min:0'],
        ];
    }
}
