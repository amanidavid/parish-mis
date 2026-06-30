<?php

namespace App\Http\Resources\Admin\V1\Billing;

use App\Http\Resources\ApiJsonResource;
use App\Models\Landlord\PropertySubscription;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;

class WorkspacePropertySubscriptionResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        $subscription = $this->relationLoaded('subscription') ? $this->subscription : null;
        $latestPayment = $this->relationLoaded('latestPayment') ? $this->latestPayment : null;
        $activePayment = $this->relationLoaded('activePayment') ? $this->getRelation('activePayment') : null;
        $recentPayments = $this->relationLoaded('payments') ? $this->payments : new Collection();

        if ($latestPayment === null && $subscription && $subscription->relationLoaded('latestPayment')) {
            $latestPayment = $subscription->latestPayment;
        }

        return [
            'uuid' => $this->uuid,
            'property_uuid' => $this->property_uuid,
            'name' => $this->property_name,
            'status' => $this->property_status,
            'current_registered_units_total' => (int) $this->current_registered_units_total,
            'is_deleted' => $this->property_deleted_at !== null,
            'property_created_at' => $this->formatTimestamp($this->property_created_at),
            'property_updated_at' => $this->formatTimestamp($this->property_updated_at),
            'property_deleted_at' => $this->formatTimestamp($this->property_deleted_at),
            'last_synced_at' => $this->formatTimestamp($this->last_synced_at),
            'subscription' => $subscription ? [
                'uuid' => $subscription->uuid,
                'status' => $subscription->status,
                'effective_status' => $subscription->effectiveStatus(),
                'current_period_starts_on' => optional($subscription->current_period_starts_on)->toDateString(),
                'current_period_ends_on' => optional($subscription->current_period_ends_on)->toDateString(),
                'last_paid_on' => optional($subscription->last_paid_on)->toDateString(),
                'activated_on' => optional($subscription->activated_on)->toDateString(),
                'expired_on' => optional($subscription->expired_on)->toDateString(),
                'billing_rule' => $subscription->billingRule ? [
                    'uuid' => $subscription->billingRule->uuid,
                    'unit_price_cents' => (int) $subscription->billingRule->unit_price_cents,
                    'currency' => $subscription->billingRule->currency,
                    'effective_from' => $subscription->billingRule->effective_from?->toDateString(),
                    'effective_to' => $subscription->billingRule->effective_to?->toDateString(),
                    'scope' => 'global_default',
                ] : null,
            ] : [
                'status' => PropertySubscription::STATUS_UNSUBSCRIBED,
                'effective_status' => PropertySubscription::STATUS_UNSUBSCRIBED,
                'current_period_starts_on' => null,
                'current_period_ends_on' => null,
                'last_paid_on' => null,
                'activated_on' => null,
                'expired_on' => null,
                'billing_rule' => null,
            ],
            'payment_summary' => [
                'payments_count' => (int) ($this->payments_count ?? 0),
                'total_paid_amount_cents' => (int) ($this->total_paid_amount_cents ?? 0),
                'active_payment' => $this->formatPaymentRecord($activePayment),
                'latest_payment' => $this->formatPaymentRecord($latestPayment),
            ],
            'recent_payment_history' => $recentPayments
                ->map(fn ($payment) => $this->formatPaymentRecord($payment))
                ->filter()
                ->values()
                ->all(),
            ...$this->timestamps(),
        ];
    }

    private function formatPaymentRecord(mixed $payment): ?array
    {
        if ($payment === null) {
            return null;
        }

        return [
            'uuid' => $payment->uuid,
            'months_paid' => (int) $payment->months_paid,
            'unit_count_at_payment' => (int) $payment->unit_count_at_payment,
            'unit_price_cents_at_payment' => (int) $payment->unit_price_cents_at_payment,
            'payment_date' => optional($payment->payment_date)->toDateString(),
            'coverage_starts_on' => optional($payment->coverage_starts_on)->toDateString(),
            'coverage_ends_on' => optional($payment->coverage_ends_on)->toDateString(),
            'monthly_price_cents' => (int) $payment->monthly_price_cents,
            'total_amount_cents' => (int) $payment->total_amount_cents,
            'currency' => $payment->currency,
            'monthly_price_formatted' => $this->formatMoneyFromCents((int) $payment->monthly_price_cents, $payment->currency),
            'total_amount_formatted' => $this->formatMoneyFromCents((int) $payment->total_amount_cents, $payment->currency),
            'reference_number' => $payment->reference_number,
            'billing_rule' => $payment->billingRule ? [
                'uuid' => $payment->billingRule->uuid,
                'unit_price_cents' => (int) $payment->billingRule->unit_price_cents,
                'currency' => $payment->billingRule->currency ?? $payment->currency,
                'price_formatted' => $this->formatMoneyFromCents(
                    (int) $payment->billingRule->unit_price_cents,
                    $payment->billingRule->currency ?? $payment->currency
                ),
                'effective_from' => $payment->billingRule->effective_from?->toDateString(),
                'effective_to' => $payment->billingRule->effective_to?->toDateString(),
                'scope' => 'global_default',
            ] : null,
        ];
    }
}
