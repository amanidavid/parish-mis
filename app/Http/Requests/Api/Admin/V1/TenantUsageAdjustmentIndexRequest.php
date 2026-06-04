<?php

namespace App\Http\Requests\Api\Admin\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantUsageAdjustmentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['pending', 'applied', 'waived', 'superseded'])],
            'adjustment_type' => ['nullable', Rule::in(['charge', 'credit', 'none'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in([
                'created_at', '-created_at',
                'effective_at', '-effective_at',
                'status', '-status',
                'adjustment_type', '-adjustment_type',
                'prorated_adjustment_cents', '-prorated_adjustment_cents',
            ])],
        ];
    }
}
