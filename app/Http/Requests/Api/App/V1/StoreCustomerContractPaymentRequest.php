<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerContractPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount_paid' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount_paid.required' => 'Provide the payment amount.',
            'amount_paid.numeric' => 'Payment amount must be a valid number.',
            'amount_paid.min' => 'Payment amount must be greater than zero.',
            'payment_date.required' => 'Provide the payment date.',
            'payment_date.date' => 'Payment date is not a valid date.',
        ];
    }
}
