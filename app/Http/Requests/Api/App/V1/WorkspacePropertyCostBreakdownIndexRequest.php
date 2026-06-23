<?php

namespace App\Http\Requests\Api\App\V1;

use App\Models\Landlord\PropertySubscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkspacePropertyCostBreakdownIndexRequest extends FormRequest
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
        ];
    }
}
