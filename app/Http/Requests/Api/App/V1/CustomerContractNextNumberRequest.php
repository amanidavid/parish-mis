<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class CustomerContractNextNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_uuid' => ['required', 'uuid'],
            'start_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'unit_uuid.required' => 'Select a unit before requesting the next contract number.',
            'unit_uuid.uuid' => 'The selected unit is invalid.',
            'start_date.date' => 'The contract start date is not a valid date.',
        ];
    }
}
