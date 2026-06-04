<?php

namespace App\Http\Requests\Api\Admin\V1\Billing;

use App\Models\Landlord\PropertySubscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantPropertySubscriptionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'property_status' => ['nullable', 'string', 'max:30'],
            'subscription_status' => ['nullable', Rule::in([
                PropertySubscription::STATUS_ACTIVE,
                PropertySubscription::STATUS_EXPIRED,
                PropertySubscription::STATUS_UNSUBSCRIBED,
            ])],
            'include_deleted' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in([
                'name', '-name',
                'property_name', '-property_name',
                'current_registered_units_total', '-current_registered_units_total',
                'current_period_ends_on', '-current_period_ends_on',
                'subscription_status', '-subscription_status',
                'created_at', '-created_at',
            ])],
        ];
    }
}
