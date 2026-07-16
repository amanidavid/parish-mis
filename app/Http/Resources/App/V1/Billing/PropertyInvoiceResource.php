<?php

namespace App\Http\Resources\App\V1\Billing;

use App\Http\Resources\ApiJsonResource;
use App\Models\Landlord\PropertyInvoice;
use Illuminate\Http\Request;

class PropertyInvoiceResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        $status = (string) $this->status;
        $currency = $this->currency;

        return [
            'uuid' => $this->uuid,
            'invoice_number' => $this->invoice_number,
            'status' => $status,
            'status_label' => match ($status) {
                PropertyInvoice::STATUS_PAID => 'Paid',
                PropertyInvoice::STATUS_OVERDUE => 'Overdue',
                default => 'Unpaid',
            },
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'period_starts_on' => $this->period_starts_on?->toDateString(),
            'period_ends_on' => $this->period_ends_on?->toDateString(),
            'payment_period_label' => trim(implode(' - ', array_filter([
                $this->period_starts_on?->toDateString(),
                $this->period_ends_on?->toDateString(),
            ]))),
            'quantity' => (int) $this->quantity,
            'unit_price_cents' => (int) $this->unit_price_cents,
            'subtotal_amount_cents' => (int) $this->subtotal_amount_cents,
            'total_amount_cents' => (int) $this->total_amount_cents,
            'balance_amount_cents' => (int) $this->balance_amount_cents,
            'currency' => $currency,
            'total_amount_formatted' => $this->formatMoneyFromCents((int) $this->total_amount_cents, $currency),
            'balance_amount_formatted' => $this->formatMoneyFromCents((int) $this->balance_amount_cents, $currency),
            'property' => $this->workspaceProperty ? [
                'uuid' => $this->workspaceProperty->property_uuid,
                'name' => $this->workspaceProperty->property_name,
                'current_registered_units_total' => (int) $this->workspaceProperty->current_registered_units_total,
            ] : null,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'uuid' => $item->uuid,
                'description' => $item->description,
                'payment_period_label' => $item->payment_period_label,
                'quantity' => (int) $item->quantity,
                'unit_price_cents' => (int) $item->unit_price_cents,
                'amount_cents' => (int) $item->amount_cents,
            ])->values()),
            'delivery' => [
                'status' => $this->sent_at ? 'sent' : 'pending',
                'last_sent_at' => $this->sent_at?->format('Y-m-d H:i:s'),
            ],
            'preview_url' => url('/api/v1/app/properties/'.$this->property_uuid.'/invoices/'.$this->uuid.'/preview'),
            'download_url' => url('/api/v1/app/properties/'.$this->property_uuid.'/invoices/'.$this->uuid.'/download'),
            ...$this->timestamps(),
        ];
    }
}
