<?php

namespace App\Http\Requests\Api\App\V1;

use Illuminate\Foundation\Http\FormRequest;

class CustomerIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:150'],
            'unit_uuid' => ['nullable', 'uuid'],
            'property_uuid' => ['nullable', 'uuid'],
            'customer_type' => ['nullable', 'in:individual,business'],
            'status' => ['nullable', 'in:active,inactive'],
            'display_name' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:display_name,-display_name,created_at,-created_at'],
        ];
    }
}
