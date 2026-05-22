<?php

namespace App\Http\Requests\Api\Admin\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBillingProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'billing_interval' => ['sometimes', 'in:monthly,quarterly,annually'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'grace_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
