<?php

namespace App\Http\Requests\Api\App\V1\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class StoreDailyExpenseTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }
}
