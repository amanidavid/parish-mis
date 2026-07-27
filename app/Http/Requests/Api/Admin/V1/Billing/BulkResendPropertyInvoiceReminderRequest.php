<?php

namespace App\Http\Requests\Api\Admin\V1\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkResendPropertyInvoiceReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(['email', 'sms', 'both'])],
            'force' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'invoice_uuids' => ['nullable', 'array', 'min:1'],
            'invoice_uuids.*' => ['uuid'],
            'delivery_log_uuids' => ['nullable', 'array', 'min:1'],
            'delivery_log_uuids.*' => ['uuid'],
            'delivery_status' => ['nullable', Rule::in(['pending', 'sent', 'failed'])],
            'source_channel' => ['nullable', Rule::in(['email', 'sms'])],
            'search_by' => ['nullable', Rule::in(['invoice_number', 'recipient_address'])],
            'search' => ['nullable', 'string', 'max:190'],
            'tenant_uuid' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    public function messages(): array
    {
        return [
            'channel.required' => 'Channel is required.',
            'channel.in' => 'Channel must be email, sms, or both.',
            'force.boolean' => 'Force must be true or false.',
            'limit.integer' => 'Limit must be a number.',
            'limit.min' => 'Limit must be at least 1.',
            'limit.max' => 'Limit may not be greater than 200.',
            'invoice_uuids.array' => 'Invoice UUIDs must be provided as a list.',
            'invoice_uuids.min' => 'Select at least one invoice UUID when using invoice UUIDs.',
            'invoice_uuids.*.uuid' => 'Each invoice UUID must be valid.',
            'delivery_log_uuids.array' => 'Delivery log UUIDs must be provided as a list.',
            'delivery_log_uuids.min' => 'Select at least one delivery log UUID when using delivery log UUIDs.',
            'delivery_log_uuids.*.uuid' => 'Each delivery log UUID must be valid.',
            'delivery_status.in' => 'Delivery status must be pending, sent, or failed.',
            'source_channel.in' => 'Source channel must be email or sms.',
            'search_by.in' => 'Search by must be invoice_number or recipient_address.',
            'search.string' => 'Search value must be text.',
            'search.max' => 'Search value may not be greater than 190 characters.',
            'tenant_uuid.uuid' => 'Tenant UUID is not valid.',
            'date_from.date' => 'Date from is not valid.',
            'date_to.date' => 'Date to is not valid.',
            'date_to.after_or_equal' => 'Date to must be after or equal to date from.',
        ];
    }
}
