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
            'contract_months' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'additional_amount_paid' => ['sometimes', 'numeric', 'min:0.01'],
            'payment_date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'in:draft,active,expired,terminated'],
            'termination_date' => ['sometimes', 'nullable', 'date'],
            'termination_reason' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_uuid.uuid' => 'The selected customer is invalid.',
            'unit_uuid.uuid' => 'The selected unit is invalid.',
            'start_date.date' => 'The contract start date is not a valid date.',
            'contract_months.integer' => 'Contract months must be a whole number.',
            'contract_months.min' => 'Contract months must be at least one month.',
            'contract_months.max' => 'Contract months cannot exceed 120 months.',
            'additional_amount_paid.numeric' => 'Additional amount paid must be a valid number.',
            'additional_amount_paid.min' => 'Additional amount paid must be greater than zero.',
            'payment_date.date' => 'The payment date is not a valid date.',
            'status.in' => 'Choose a valid contract status.',
            'termination_date.date' => 'The termination date is not a valid date.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var CustomerContract|null $contract */
            $contract = $this->route('customerContract');

            $status = $this->input('status') ?? $contract?->status ?? 'draft';
            $startDate = $this->input('start_date') ?? $contract?->start_date?->toDateString();
            $terminationDate = $this->input('termination_date') ?? $contract?->termination_date?->toDateString();

            if ($terminationDate !== null && $startDate !== null && $terminationDate < $startDate) {
                $validator->errors()->add('termination_date', 'Termination date must be the same as or after the contract start date.');
            }

            if ($status === 'terminated' && blank($terminationDate)) {
                $validator->errors()->add('termination_date', 'Provide the termination date when marking a contract as terminated.');
            }

            $this->validateStatusDateRules($validator, $status, $startDate, null);
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
            $validator->errors()->add('start_date', 'Draft contracts can only start today or on a future date.');
        }

    }
}
