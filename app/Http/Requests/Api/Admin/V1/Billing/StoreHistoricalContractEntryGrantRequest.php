<?php

namespace App\Http\Requests\Api\Admin\V1\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreHistoricalContractEntryGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_uuid' => ['nullable', 'uuid'],
            'historical_start_date_from' => ['required', 'date', 'before_or_equal:today'],
            'historical_start_date_to' => ['required', 'date', 'after_or_equal:historical_start_date_from', 'before_or_equal:today'],
            'usable_from' => ['nullable', 'date'],
            'expires_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'meta' => ['nullable', 'array'],
            'meta.support_ticket' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $expiresAt = $this->date('expires_at');
            $usableFrom = $this->date('usable_from');

            if ($expiresAt && $expiresAt->lte(now())) {
                $validator->errors()->add('expires_at', 'The grant expiry time must be in the future.');
            }

            if ($expiresAt && $usableFrom && $expiresAt->lte($usableFrom)) {
                $validator->errors()->add('expires_at', 'The grant expiry time must be after the grant usable time.');
            }
        }];
    }
}
