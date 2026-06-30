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
            'status' => ['nullable', 'in:active,inactive'],
            'effective_on' => ['nullable', 'date'],
            'sort' => ['nullable', 'in:unit_price_cents,-unit_price_cents,effective_from,-effective_from,created_at,-created_at'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
