<?php

namespace App\Http\Requests\Api\App\V1;

use App\Models\Tenant\Unit;
use Illuminate\Foundation\Http\FormRequest;

class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_floor_uuid' => ['required', 'uuid'],
            'unit_number' => ['required', 'string', 'min:1', 'max:120'],
            'monthly_rent_amount' => ['required', 'numeric', 'min:0.01'],
            'rent_currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', 'in:'.implode(',', Unit::MANUAL_STATUSES)],
        ];
    }

    public function messages(): array
    {
        return [
            'monthly_rent_amount.required' => 'Set the monthly unit price before saving the unit.',
            'monthly_rent_amount.numeric' => 'The monthly unit price must be a valid number.',
            'monthly_rent_amount.min' => 'The monthly unit price must be greater than zero.',
            'rent_currency.size' => 'Unit currency must be a valid 3-letter code.',
            'status.in' => 'Unit status can only be set manually to vacant or maintenance. Occupied is assigned automatically when an active contract exists.',
        ];
    }
}
