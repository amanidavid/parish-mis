<?php

namespace App\Http\Resources\Admin\V1\Billing;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class PropertySubscriptionPaymentResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        $currency = $this->currency;
        $invoice = $this->getRelationValue('generatedInvoice');

        return [
            'uuid' => $this->uuid,
            'months_paid' => (int) $this->months_paid,
            'unit_count_at_payment' => (int) $this->unit_count_at_payment,
            'unit_price_cents_at_payment' => (int) $this->unit_price_cents_at_payment,
            'monthly_price_cents' => (int) $this->monthly_price_cents,
            'total_amount_cents' => (int) $this->total_amount_cents,
            'currency' => $currency,
            'monthly_price_formatted' => $this->formatMoneyFromCents((int) $this->monthly_price_cents, $currency),
            'total_amount_formatted' => $this->formatMoneyFromCents((int) $this->total_amount_cents, $currency),
            'payment_date' => optional($this->payment_date)->toDateString(),
            'coverage_starts_on' => optional($this->coverage_starts_on)->toDateString(),
            'coverage_ends_on' => optional($this->coverage_ends_on)->toDateString(),
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'recorded_by_name' => data_get($this->meta, 'recorded_by_name'),
            'property' => $this->workspaceProperty ? [
                'uuid' => $this->workspaceProperty->property_uuid,
                'name' => $this->workspaceProperty->property_name,
                'status' => $this->workspaceProperty->property_status,
                'current_registered_units_total' => (int) $this->workspaceProperty->current_registered_units_total,
                'is_deleted' => $this->workspaceProperty->property_deleted_at !== null,
            ] : null,
            'subscription' => $this->propertySubscription ? [
                'uuid' => $this->propertySubscription->uuid,
                'status' => $this->propertySubscription->status,
                'effective_status' => $this->propertySubscription->effectiveStatus(),
                'current_period_starts_on' => optional($this->propertySubscription->current_period_starts_on)->toDateString(),
                'current_period_ends_on' => optional($this->propertySubscription->current_period_ends_on)->toDateString(),
                'last_paid_on' => optional($this->propertySubscription->last_paid_on)->toDateString(),
            ] : null,
            'billing_rule' => $this->billingRule ? [
                'uuid' => $this->billingRule->uuid,
                'unit_price_cents' => (int) $this->billingRule->unit_price_cents,
                'currency' => $this->billingRule->currency ?? $currency,
                'price_formatted' => $this->formatMoneyFromCents(
                    (int) $this->billingRule->unit_price_cents,
                    $this->billingRule->currency ?? $currency
                ),
                'effective_from' => $this->billingRule->effective_from?->toDateString(),
                'effective_to' => $this->billingRule->effective_to?->toDateString(),
                'scope' => 'global_default',
            ] : null,
            'invoice' => $invoice ? [
                'uuid' => $invoice->uuid,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'issue_date' => optional($invoice->issue_date)->toDateString(),
                'due_date' => optional($invoice->due_date)->toDateString(),
                'currency' => $invoice->currency,
                'total_amount_cents' => (int) $invoice->total_amount_cents,
                'balance_amount_cents' => (int) $invoice->balance_amount_cents,
                'delivery' => [
                    'status' => $invoice->sent_at ? 'sent' : 'pending',
                    'last_sent_at' => optional($invoice->sent_at)->format('Y-m-d H:i:s'),
                ],
                'preview_url' => url('/api/v1/app/properties/'.$invoice->property_uuid.'/invoices/'.$invoice->uuid.'/preview'),
                'download_url' => url('/api/v1/app/properties/'.$invoice->property_uuid.'/invoices/'.$invoice->uuid.'/download'),
            ] : null,
            ...$this->timestamps(),
        ];
    }
}
