<?php

namespace App\Http\Requests\Api\Admin\V1\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HistoricalContractEntryGrantIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['active', 'revoked'])],
            'scope' => ['nullable', Rule::in(['workspace', 'property'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
