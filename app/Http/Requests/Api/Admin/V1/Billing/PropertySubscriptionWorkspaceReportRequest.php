<?php

namespace App\Http\Requests\Api\Admin\V1\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PropertySubscriptionWorkspaceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'workspace_status' => ['nullable', 'string', 'max:30'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in([
                'workspace_name', '-workspace_name',
                'workspace_status', '-workspace_status',
                'total_properties', '-total_properties',
                'active_subscribed_properties', '-active_subscribed_properties',
                'expired_properties', '-expired_properties',
                'unsubscribed_properties', '-unsubscribed_properties',
                'payments_count', '-payments_count',
                'total_collected_amount_cents', '-total_collected_amount_cents',
            ])],
        ];
    }
}
