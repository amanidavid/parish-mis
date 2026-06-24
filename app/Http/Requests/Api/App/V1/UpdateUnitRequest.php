<?php

namespace App\Http\Requests\Api\App\V1;

use App\Models\Tenant\Unit;
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
            'status' => ['sometimes', 'in:'.implode(',', Unit::MANUAL_STATUSES)],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Unit status can only be set manually to vacant or maintenance. Occupied is assigned automatically when an active contract exists.',
        ];
    }
}
