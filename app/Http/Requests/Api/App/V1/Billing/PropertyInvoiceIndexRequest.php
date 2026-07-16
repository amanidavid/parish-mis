<?php

namespace App\Http\Requests\Api\App\V1\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PropertyInvoiceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['paid', 'unpaid', 'overdue'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in([
                'issue_date', '-issue_date',
                'due_date', '-due_date',
                'status', '-status',
                'total_amount_cents', '-total_amount_cents',
                'created_at', '-created_at',
            ])],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Status must be paid, unpaid, or overdue.',
            'start_date.date' => 'Start date is not valid.',
            'end_date.date' => 'End date is not valid.',
            'end_date.after_or_equal' => 'End date must be after or equal to start date.',
            'per_page.integer' => 'Per page must be a number.',
            'per_page.min' => 'Per page must be at least 1.',
            'per_page.max' => 'Per page may not be greater than 100.',
            'sort.in' => 'Sort value is not valid.',
        ];
    }
}
