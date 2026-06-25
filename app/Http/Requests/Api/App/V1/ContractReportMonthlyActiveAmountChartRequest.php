<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class ContractReportMonthlyActiveAmountChartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_uuid' => ['nullable', 'uuid'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
