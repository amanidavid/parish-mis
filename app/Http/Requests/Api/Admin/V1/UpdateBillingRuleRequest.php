<?php

namespace App\Http\Requests\Api\Admin\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBillingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'range_start' => ['sometimes', 'integer', 'min:1'],
            'range_end' => ['sometimes', 'nullable', 'integer'],
            'price_cents' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
