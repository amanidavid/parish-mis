<?php

namespace App\Http\Requests\Api\Admin\V1\Billing;

use App\Models\Landlord\PropertySubscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PropertySubscriptionExpiredIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([
                PropertySubscription::STATUS_EXPIRED,
                PropertySubscription::STATUS_UNSUBSCRIBED,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in([
                'workspace_name', '-workspace_name',
                'current_period_ends_on', '-current_period_ends_on',
                'current_registered_units_total', '-current_registered_units_total',
            ])],
        ];
    }
}
