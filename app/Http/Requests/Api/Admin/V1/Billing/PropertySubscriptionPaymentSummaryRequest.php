<?php

namespace App\Http\Requests\Api\Admin\V1\Billing;

use Illuminate\Foundation\Http\FormRequest;

class PropertySubscriptionPaymentSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_uuid' => ['nullable', 'uuid'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
