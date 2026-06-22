<?php

namespace App\Http\Requests\Api\App\V1\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaintenanceJobRequest extends FormRequest
{
    private const ALLOWED_STATUSES = ['open', 'in_progress', 'closed'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_uuid' => ['required', 'uuid'],
            'property_floor_uuid' => ['nullable', 'uuid'],
            'unit_uuid' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', 'in:open,in_progress,closed'],
            'reported_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'property_uuid.required' => 'Select a property before saving the maintenance job.',
            'property_uuid.uuid' => 'The selected property is invalid.',
            'property_floor_uuid.uuid' => 'The selected floor is invalid.',
            'unit_uuid.uuid' => 'The selected unit is invalid.',
            'title.required' => 'Enter the maintenance job title.',
            'title.min' => 'The maintenance job title must be at least 2 characters.',
            'title.max' => 'The maintenance job title cannot exceed 160 characters.',
            'description.max' => 'The maintenance job description cannot exceed 5000 characters.',
            'status.in' => 'Choose a valid maintenance job status: open, in progress, or closed.',
            'reported_date.date' => 'The reported date is not a valid date.',
        ];
    }
}
