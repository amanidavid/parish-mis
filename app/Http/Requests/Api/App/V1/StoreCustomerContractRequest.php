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
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', 'in:draft,active,expired,terminated'],
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
            'end_date.date' => 'The contract end date is not a valid date.',
            'end_date.after_or_equal' => 'The contract end date must be the same as or after the start date.',
            'amount.required' => 'Enter the contract amount.',
            'amount.numeric' => 'The contract amount must be a valid number.',
            'amount.min' => 'The contract amount cannot be less than zero.',
            'currency.size' => 'Currency must be a valid 3-letter code.',
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
                $this->input('end_date')
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

        if ($status === 'active' && $endDate !== null && $endDate < $today) {
            $validator->errors()->add('end_date', 'Active contracts cannot use a past end date. Change the status to expired or terminated instead.');
        }

        if ($status === 'expired') {
            if ($endDate === null) {
                $validator->errors()->add('end_date', 'Expired contracts must have an end date before today.');

                return;
            }

            if ($endDate >= $today) {
                $validator->errors()->add('end_date', 'Expired contracts must use an end date before today.');
            }
        }
    }
}
