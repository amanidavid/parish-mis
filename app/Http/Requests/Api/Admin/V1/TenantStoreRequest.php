<?php

namespace App\Http\Requests\Api\Admin\V1;

use Illuminate\Foundation\Http\FormRequest;

class TenantStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'display_name' => ['nullable', 'string', 'min:2', 'max:120'],
            'database' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_]+$/', 'min:2', 'max:64'],
            'owner_uuid' => ['required', 'string', 'uuid'],
            'plan_uuid' => ['nullable', 'string', 'uuid'],
            'billing_profile_uuid' => ['nullable', 'string', 'uuid'],
        ];
    }
}
