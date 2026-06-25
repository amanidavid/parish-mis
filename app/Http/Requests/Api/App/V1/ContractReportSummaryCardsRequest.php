<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractReportSummaryCardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_uuid' => ['nullable', 'uuid'],
            'range' => ['nullable', Rule::in(['3_months', '6_months', '12_months', 'custom'])],
            'start_date' => ['nullable', 'date', 'required_if:range,custom'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', 'required_if:range,custom'],
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.required_if' => 'Provide a start date when using a custom contract report range.',
            'end_date.required_if' => 'Provide an end date when using a custom contract report range.',
            'end_date.after_or_equal' => 'The contract report end date must be the same as or after the start date.',
        ];
    }
}
