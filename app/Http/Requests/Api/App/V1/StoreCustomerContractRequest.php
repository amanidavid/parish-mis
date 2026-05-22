<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_uuid' => ['required', 'uuid'],
            'unit_uuid' => ['required', 'uuid'],
            'contract_number' => ['required', 'string', 'min:1', 'max:120'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'billing_cycle' => ['nullable', 'in:monthly,quarterly,semi_annually,annually,one_time'],
            'status' => ['nullable', 'in:draft,active,expired,terminated,renewed'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
