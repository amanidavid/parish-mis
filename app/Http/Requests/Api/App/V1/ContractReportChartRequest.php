<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractReportChartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_uuid' => ['required', 'uuid'],
            'range' => ['nullable', Rule::in(['today', 'last_7_days', 'last_30_days', 'this_month', 'last_12_months', 'this_year', 'custom'])],
            'period' => ['nullable', Rule::in(['day', 'month', 'year'])],
            'start_date' => ['nullable', 'date', 'required_if:range,custom'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', 'required_if:range,custom'],
            'metric' => ['nullable', Rule::in(['revenue_collected', 'gross_collected_amount', 'refund_amount'])],
        ];
    }
}
