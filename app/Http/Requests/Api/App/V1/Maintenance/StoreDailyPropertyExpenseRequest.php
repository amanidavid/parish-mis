<?php

namespace App\Http\Requests\Api\App\V1\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class StoreDailyPropertyExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_uuid' => ['required', 'uuid'],
            'expense_type_uuid' => ['required', 'uuid'],
            'description' => ['nullable', 'string', 'max:5000'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_date' => ['nullable', 'date'],
        ];
    }
}
