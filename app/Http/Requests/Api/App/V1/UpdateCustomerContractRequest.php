<?php

namespace App\Http\Requests\Api\App\V1;

use App\Models\Tenant\CustomerContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
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
            'status' => ['sometimes', 'in:draft,active,expired,terminated'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_uuid.uuid' => 'The selected customer is invalid.',
            'unit_uuid.uuid' => 'The selected unit is invalid.',
            'start_date.date' => 'The contract start date is not a valid date.',
            'end_date.date' => 'The contract end date is not a valid date.',
            'amount.numeric' => 'The contract amount must be a valid number.',
            'amount.min' => 'The contract amount cannot be less than zero.',
            'currency.size' => 'Currency must be a valid 3-letter code.',
            'status.in' => 'Choose a valid contract status.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var CustomerContract|null $contract */
            $contract = $this->route('customer_contract');

            $status = $this->input('status') ?? $contract?->status ?? 'draft';
            $startDate = $this->input('start_date') ?? $contract?->start_date?->toDateString();
            $endDate = $this->input('end_date');

            if ($endDate === null && !$this->has('end_date')) {
                $endDate = $contract?->end_date?->toDateString();
            }

            if ($startDate && $endDate && $endDate < $startDate) {
                $validator->errors()->add('end_date', 'The contract end date must be the same as or after the start date.');
            }

            $this->validateStatusDateRules($validator, $status, $startDate, $endDate);
        });
    }

    /**
     * Validate contract status against contract dates.
     */
    private function validateStatusDateRules(Validator $validator, ?string $status, ?string $startDate, ?string $endDate): void
    {
        if (!$status || !$startDate) {
            return;
        }

        $today = Carbon::today()->toDateString();

        if ($status === 'draft' && $startDate < $today) {
            $validator->errors()->add('status', 'Draft contracts can only use today or a future start date.');
        }

        if ($status === 'active' && $endDate !== null && $endDate < $today) {
            $validator->errors()->add('status', 'Active contracts cannot have an end date in the past. Use expired or terminated instead.');
        }

        if ($status === 'expired') {
            if ($endDate === null) {
                $validator->errors()->add('status', 'Expired contracts must have an end date.');

                return;
            }

            if ($endDate >= $today) {
                $validator->errors()->add('status', 'Expired contracts must have an end date before today.');
            }
        }
    }
}
