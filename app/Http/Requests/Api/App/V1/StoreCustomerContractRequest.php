<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

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
            'start_date' => ['required', 'date'],
            'contract_months' => ['required', 'integer', 'min:1', 'max:120'],
            'initial_amount_paid' => ['nullable', 'numeric', 'min:0'],
            'payment_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:draft,active'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_uuid.required' => 'Select a customer before saving the contract.',
            'customer_uuid.uuid' => 'The selected customer is invalid.',
            'unit_uuid.required' => 'Select a unit before saving the contract.',
            'unit_uuid.uuid' => 'The selected unit is invalid.',
            'start_date.required' => 'Enter the contract start date.',
            'start_date.date' => 'The contract start date is not a valid date.',
            'contract_months.required' => 'Enter the contract months before saving the contract.',
            'contract_months.integer' => 'Contract months must be a whole number.',
            'contract_months.min' => 'Contract months must be at least one month.',
            'contract_months.max' => 'Contract months cannot exceed 120 months.',
            'initial_amount_paid.numeric' => 'Initial amount paid must be a valid number.',
            'initial_amount_paid.min' => 'Initial amount paid cannot be less than zero.',
            'payment_date.date' => 'The payment date is not a valid date.',
            'status.in' => 'Choose a valid contract status.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateStatusDateRules(
                $validator,
                $this->input('status', 'draft'),
                $this->input('start_date'),
                null
            );
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
