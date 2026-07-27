<?php

namespace App\Http\Resources\Admin\V1\Billing;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class PropertyInvoiceDeliveryLogResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'delivery_log_uuid' => $this->uuid,
            'invoice_uuid' => $this->invoice_uuid,
            'property_invoice_id' => (int) $this->property_invoice_id,
            'invoice_number' => $this->invoice_number,
            'workspace_uuid' => $this->workspace_uuid,
            'workspace_name' => $this->workspace_name,
            'property_uuid' => $this->property_uuid,
            'property_name' => $this->property_name,
            'recipient_name' => $this->recipient_name,
            'recipient_address' => $this->recipient_address,
            'channel' => $this->channel,
            'kind' => $this->kind,
            'status' => $this->status,
            'subject' => $this->subject,
            'message' => $this->message,
            'attempts_count' => (int) $this->attempts_count,
            'due_date' => $this->due_date,
            'issue_date' => $this->issue_date,
            'last_attempt_at' => $this->formatTimestamp($this->last_attempt_at),
            'sent_at' => $this->formatTimestamp($this->sent_at),
            'created_at' => $this->formatTimestamp($this->created_at),
            'updated_at' => $this->formatTimestamp($this->updated_at),
        ];
    }
}
