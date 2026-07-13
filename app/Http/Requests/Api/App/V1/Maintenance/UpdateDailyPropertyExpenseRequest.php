<?php

namespace App\Http\Requests\Api\App\V1\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDailyPropertyExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_uuid' => ['sometimes', 'uuid'],
            'expense_type_uuid' => ['sometimes', 'uuid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'expense_date' => ['sometimes', 'date'],
        ];
    }
}
