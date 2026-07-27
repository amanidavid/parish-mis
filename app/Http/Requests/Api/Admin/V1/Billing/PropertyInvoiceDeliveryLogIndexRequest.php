<?php

namespace App\Http\Requests\Api\Admin\V1\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PropertyInvoiceDeliveryLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['pending', 'sent', 'failed'])],
            'channel' => ['nullable', Rule::in(['email', 'sms'])],
            'kind' => ['nullable', Rule::in(['paid_invoice_email', 'invoice_reminder_email', 'invoice_reminder_sms'])],
            'search' => ['nullable', 'string', 'max:190'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'workspace_uuid' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in([
                'last_attempt_at', '-last_attempt_at',
                'due_date', '-due_date',
                'status', '-status',
                'created_at', '-created_at',
            ])],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Status must be pending, sent, or failed.',
            'channel.in' => 'Channel must be email or sms.',
            'kind.in' => 'Kind must be paid_invoice_email, invoice_reminder_email, or invoice_reminder_sms.',
            'search.string' => 'Search value must be text.',
            'search.max' => 'Search value may not be greater than 190 characters.',
            'date_from.date' => 'Date from is not valid.',
            'date_to.date' => 'Date to is not valid.',
            'date_to.after_or_equal' => 'Date to must be after or equal to date from.',
            'workspace_uuid.uuid' => 'Workspace UUID is not valid.',
            'per_page.integer' => 'Per page must be a number.',
            'per_page.min' => 'Per page must be at least 1.',
            'per_page.max' => 'Per page may not be greater than 100.',
            'sort.in' => 'Sort value is not valid.',
        ];
    }
}
