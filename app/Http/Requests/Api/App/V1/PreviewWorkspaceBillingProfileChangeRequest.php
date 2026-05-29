<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewWorkspaceBillingProfileChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_profile_uuid' => ['required', 'uuid'],
            'change_timing' => ['nullable', 'string', Rule::in(['immediate_prorated', 'next_cycle'])],
            'effective_at' => ['nullable', 'date'],
        ];
    }
}
