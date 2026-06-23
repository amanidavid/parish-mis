<?php

namespace App\Http\Requests\Api\Admin\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignTenantBillingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_rule_uuid' => ['required', 'uuid'],
            'change_timing' => ['nullable', 'string', Rule::in(['immediate_prorated', 'next_cycle'])],
            'effective_at' => ['nullable', 'date'],
        ];
    }
}
