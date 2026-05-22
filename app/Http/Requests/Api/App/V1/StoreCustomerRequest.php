<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_type' => ['required', 'in:individual,business'],
            'display_name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['nullable', 'email:rfc,dns', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'in:active,inactive'],
            'notes' => ['nullable', 'string'],
            'business_details' => ['nullable', 'array'],
            'business_details.business_name' => [
                Rule::requiredIf(fn () => $this->input('customer_type') === 'business'),
                'nullable',
                'string',
                'max:150',
            ],
            'business_details.registration_number' => ['nullable', 'string', 'max:120'],
            'business_details.tax_identifier' => ['nullable', 'string', 'max:120'],
            'business_details.contact_person_name' => ['nullable', 'string', 'max:150'],
            'business_details.contact_person_phone' => ['nullable', 'string', 'max:30'],
            'business_details.address_line' => ['nullable', 'string', 'max:255'],
        ];
    }
}
