<?php

namespace App\Http\Requests\Api\Admin\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantPropertyLocationBreakdownRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group_by' => ['required', Rule::in(['country', 'region', 'district', 'ward'])],
            'status' => ['nullable', 'in:active,inactive'],
            'country_uuid' => ['nullable', 'uuid'],
            'region_uuid' => ['nullable', 'uuid'],
            'district_uuid' => ['nullable', 'uuid'],
            'ward_uuid' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', Rule::in(['name', '-name', 'properties_count', '-properties_count'])],
        ];
    }
}
