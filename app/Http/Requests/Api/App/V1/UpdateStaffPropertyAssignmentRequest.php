<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffPropertyAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_uuid' => ['sometimes', 'uuid'],
            'property_uuid' => ['sometimes', 'uuid'],
        ];
    }
}
