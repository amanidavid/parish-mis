<?php

namespace App\Http\Requests\Api\Admin\V1;

use Illuminate\Foundation\Http\FormRequest;

class BillingProfileIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,inactive'],
            'billing_interval' => ['nullable', 'in:monthly,quarterly,annually'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
