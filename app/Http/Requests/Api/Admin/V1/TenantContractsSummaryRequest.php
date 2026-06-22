<?php

namespace App\Http\Requests\Api\Admin\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantContractsSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_uuid' => ['nullable', 'uuid'],
            'country_uuid' => ['nullable', 'uuid'],
            'region_uuid' => ['nullable', 'uuid'],
            'district_uuid' => ['nullable', 'uuid'],
            'ward_uuid' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'expired', 'terminated'])],
            'billing_cycle' => ['nullable', Rule::in(['monthly', 'quarterly', 'semi_annually', 'annually', 'one_time'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'expiring_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
