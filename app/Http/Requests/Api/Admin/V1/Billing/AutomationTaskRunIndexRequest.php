<?php

namespace App\Http\Requests\Api\Admin\V1\Billing;

use Illuminate\Foundation\Http\FormRequest;

class AutomationTaskRunIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
