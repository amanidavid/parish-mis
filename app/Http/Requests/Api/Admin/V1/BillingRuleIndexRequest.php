<?php

namespace App\Http\Requests\Api\Admin\V1;

use Illuminate\Foundation\Http\FormRequest;

class BillingRuleIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_profile_uuid' => ['nullable', 'uuid'],
            'registered_units' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
            'effective_on' => ['nullable', 'date'],
            'sort' => ['nullable', 'in:range_start,-range_start,price_cents,-price_cents,effective_from,-effective_from,sort_order,-sort_order,created_at,-created_at'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
