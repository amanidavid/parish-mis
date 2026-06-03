<?php

namespace App\Http\Requests\Api\App\V1\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class MaintenanceExpenseIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'maintenance_job_uuid' => ['nullable', 'uuid'],
            'property_uuid' => ['nullable', 'uuid'],
            'property_floor_uuid' => ['nullable', 'uuid'],
            'unit_uuid' => ['nullable', 'uuid'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:expense_date,-expense_date,amount,-amount,title,-title,created_at,-created_at'],
        ];
    }
}
