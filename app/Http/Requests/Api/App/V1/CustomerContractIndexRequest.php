<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class CustomerContractIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'property_uuid' => ['nullable', 'uuid'],
            'customer_uuid' => ['nullable', 'uuid'],
            'unit_uuid' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:draft,active,expired,terminated'],
            'payment_status' => ['nullable', 'in:unpaid,partial,paid'],
            'contract_number' => ['nullable', 'string', 'max:120'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:start_date,-start_date,contract_number,-contract_number,created_at,-created_at'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'The contract end date filter must be the same as or after the start date filter.',
        ];
    }
}
