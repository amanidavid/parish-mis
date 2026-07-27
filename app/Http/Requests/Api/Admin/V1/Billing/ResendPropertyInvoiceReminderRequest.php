<?php

namespace App\Http\Requests\Api\Admin\V1\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResendPropertyInvoiceReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(['email', 'sms', 'both'])],
            'force' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'channel.required' => 'Channel is required.',
            'channel.in' => 'Channel must be email, sms, or both.',
            'force.boolean' => 'Force must be true or false.',
        ];
    }
}
