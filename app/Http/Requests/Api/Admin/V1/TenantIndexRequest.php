<?php

namespace App\Http\Requests\Api\Admin\V1;

use Illuminate\Foundation\Http\FormRequest;

class TenantIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'name' => ['nullable', 'string', 'max:120'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,suspended'],
            'provisioning_status' => ['nullable', 'in:pending,provisioning,ready,failed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
