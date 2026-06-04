<?php

namespace App\Http\Requests\Api\Admin\V1\Billing;

use App\Models\Landlord\AutomationTaskSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAutomationTaskSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'schedule_mode' => ['sometimes', Rule::in([
                AutomationTaskSetting::MODE_INTERVAL,
                AutomationTaskSetting::MODE_DAILY,
            ])],
            'interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440', 'required_if:schedule_mode,'.AutomationTaskSetting::MODE_INTERVAL],
            'run_at_time' => ['nullable', 'date_format:H:i', 'required_if:schedule_mode,'.AutomationTaskSetting::MODE_DAILY],
            'timezone' => ['nullable', 'timezone'],
        ];
    }
}
