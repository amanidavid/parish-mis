<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractReportByPropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_uuid' => ['nullable', 'uuid'],
            'customer_uuid' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'expired', 'terminated', 'renewed'])],
            'billing_cycle' => ['nullable', Rule::in(['monthly', 'quarterly', 'semi_annually', 'annually', 'one_time'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:name,-name,contracts_count,-contracts_count,total_contract_amount,-total_contract_amount,active_contract_amount,-active_contract_amount,latest_end_date,-latest_end_date'],
        ];
    }
}
