<?php

namespace App\Http\Requests\Api\App\V1;

use App\Models\Tenant\CustomerContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCustomerContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_uuid' => ['sometimes', 'uuid'],
            'unit_uuid' => ['sometimes', 'nullable', 'uuid'],
            'contract_number' => ['sometimes', 'string', 'min:1', 'max:120'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'billing_cycle' => ['sometimes', 'in:monthly,quarterly,semi_annually,annually,one_time'],
            'status' => ['sometimes', 'in:draft,active,expired,terminated,renewed'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var CustomerContract|null $contract */
            $contract = $this->route('customer_contract');

            $startDate = $this->input('start_date') ?? $contract?->start_date?->toDateString();
            $endDate = $this->input('end_date');

            if ($endDate === null && !$this->has('end_date')) {
                $endDate = $contract?->end_date?->toDateString();
            }

            if ($startDate && $endDate && $endDate < $startDate) {
                $validator->errors()->add('end_date', 'The end date must be a date after or equal to the start date.');
            }
        });
    }
}
