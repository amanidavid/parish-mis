<?php

namespace App\Http\Requests\Api\Admin\V1\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantPropertySubscriptionPaymentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_uuid' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:120'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in([
                'payment_date', '-payment_date',
                'total_amount_cents', '-total_amount_cents',
                'coverage_ends_on', '-coverage_ends_on',
                'created_at', '-created_at',
            ])],
        ];
    }
}
