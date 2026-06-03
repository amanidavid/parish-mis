<?php

namespace App\Http\Requests\Api\App\V1\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMaintenanceJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_uuid' => ['sometimes', 'uuid'],
            'property_floor_uuid' => ['sometimes', 'nullable', 'uuid'],
            'unit_uuid' => ['sometimes', 'nullable', 'uuid'],
            'title' => ['sometimes', 'string', 'min:2', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'reported_date' => ['sometimes', 'date'],
        ];
    }
}
