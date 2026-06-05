<?php

namespace App\Http\Requests\Api\Admin\V1\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminAnalyticsTrendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['required', Rule::in(['week', 'month', 'year', 'custom'])],
            'anchor_date' => ['nullable', 'date'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'bucket_by' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'include_cumulative' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Custom ranges must be fully explicit so chart buckets are reproducible and cacheable.
            if ($this->input('period') !== 'custom') {
                return;
            }

            if (!$this->filled('start_date') || !$this->filled('end_date')) {
                $validator->errors()->add('period', 'Custom analytics period requires both start_date and end_date.');
            }
        });
    }
}
