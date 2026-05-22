<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_type' => ['sometimes', 'in:individual,business'],
            'display_name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'email' => ['sometimes', 'nullable', 'email:rfc,dns', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'status' => ['sometimes', 'in:active,inactive'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'business_details' => ['sometimes', 'nullable', 'array'],
            'business_details.business_name' => [
                Rule::requiredIf(fn () => $this->input('customer_type') === 'business'),
                'nullable',
                'string',
                'max:150',
            ],
            'business_details.registration_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'business_details.tax_identifier' => ['sometimes', 'nullable', 'string', 'max:120'],
            'business_details.contact_person_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'business_details.contact_person_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'business_details.address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
