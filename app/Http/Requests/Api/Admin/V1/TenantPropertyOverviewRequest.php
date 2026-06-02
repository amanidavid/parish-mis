<?php

namespace App\Http\Requests\Api\Admin\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantPropertyOverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'in:active,inactive'],
            'country_uuid' => ['nullable', 'uuid'],
            'region_uuid' => ['nullable', 'uuid'],
            'district_uuid' => ['nullable', 'uuid'],
            'ward_uuid' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => [
                'nullable',
                'string',
                Rule::in([
                    'name', '-name',
                    'status', '-status',
                    'floors_count', '-floors_count',
                    'units_count', '-units_count',
                    'occupied_units', '-occupied_units',
                    'vacant_units', '-vacant_units',
                    'customers_count', '-customers_count',
                    'contracts_count', '-contracts_count',
                    'active_contracts_count', '-active_contracts_count',
                ]),
            ],
        ];
    }
}
