<?php

namespace App\Http\Requests\Api\Admin\V1;

use Illuminate\Foundation\Http\FormRequest;

class TenantPropertyLocationSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:active,inactive'],
            'country_uuid' => ['nullable', 'uuid'],
            'region_uuid' => ['nullable', 'uuid'],
            'district_uuid' => ['nullable', 'uuid'],
            'ward_uuid' => ['nullable', 'uuid'],
        ];
    }
}
