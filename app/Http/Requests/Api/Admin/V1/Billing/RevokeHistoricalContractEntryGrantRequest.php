<?php

namespace App\Http\Requests\Api\Admin\V1\Billing;

use Illuminate\Foundation\Http\FormRequest;

class RevokeHistoricalContractEntryGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
