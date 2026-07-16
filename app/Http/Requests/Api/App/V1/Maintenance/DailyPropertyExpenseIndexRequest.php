<?php

namespace App\Http\Requests\Api\App\V1\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class DailyPropertyExpenseIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_filter' => ['nullable', 'in:last_30_days,custom'],
            'search' => ['nullable', 'string', 'max:120'],
            'property_uuid' => ['nullable', 'uuid'],
            'expense_type_uuid' => ['nullable', 'uuid'],
            'start_date' => ['nullable', 'date', 'required_if:date_filter,custom'],
            'end_date' => ['nullable', 'date', 'required_if:date_filter,custom', 'after_or_equal:start_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:expense_date,-expense_date,amount,-amount,title,-title,created_at,-created_at'],
        ];
    }
}
