<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractReportSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_uuid' => ['nullable', 'uuid'],
            'customer_uuid' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'expired', 'terminated', 'renewed'])],
            'billing_cycle' => ['nullable', Rule::in(['monthly', 'quarterly', 'semi_annually', 'annually', 'one_time'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
