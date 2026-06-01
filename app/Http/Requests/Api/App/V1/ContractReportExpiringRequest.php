<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractReportExpiringRequest extends FormRequest
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
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:end_date,-end_date,amount,-amount,contract_number,-contract_number,customer_name,-customer_name,property_name,-property_name'],
        ];
    }
}
