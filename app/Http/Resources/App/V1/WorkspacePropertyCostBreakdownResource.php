<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use App\Models\Landlord\PropertySubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WorkspacePropertyCostBreakdownResource extends ApiJsonResource
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

        $effectiveStatus = $subscription?->effectiveStatus() ?? PropertySubscription::STATUS_UNSUBSCRIBED;
        $paymentState = $this->paymentState($subscription, $effectiveStatus);
        $currentPeriodStartsOn = optional($subscription?->current_period_starts_on)->toDateString();
        $currentPeriodEndsOn = optional($subscription?->current_period_ends_on)->toDateString();
        $billingRule = $subscription?->billingRule;
        $billingProfile = $billingRule?->profile;
        $monthlyPriceCents = (int) (
            $activePayment?->monthly_price_cents
            ?? $latestPayment?->monthly_price_cents
            ?? $billingRule?->price_cents
            ?? 0
        );
        $currency = $activePayment?->currency
            ?? $latestPayment?->currency
            ?? $billingProfile?->currency
            ?? 'TZS';
        $paymentsCount = (int) ($this->payments_count ?? 0);
        $totalPaidAmountCents = (int) ($this->total_paid_amount_cents ?? 0);
        $unitRange = $this->formatUnitRange($billingRule?->range_start, $billingRule?->range_end);

        return [
            'uuid' => $this->uuid,
            'property_uuid' => $this->property_uuid,
            'property_name' => $this->property_name,
            'property_status' => $this->property_status,
            'current_registered_units_total' => (int) $this->current_registered_units_total,
            'is_deleted' => $this->property_deleted_at !== null,
            'subscription_status' => $effectiveStatus,
            'current_period_starts_on' => $currentPeriodStartsOn,
            'current_period_ends_on' => $currentPeriodEndsOn,
            'current_period_label' => $this->formatPeriodLabel($currentPeriodStartsOn, $currentPeriodEndsOn),
            'total_paid_amount_cents' => $totalPaidAmountCents,
            'payments_count' => $paymentsCount,
            'monthly_price_cents' => $monthlyPriceCents,
            'currency' => $currency,
            'billing_interval' => $billingProfile?->billing_interval ?? 'monthly',
            'billing_profile_name' => $billingProfile?->name ?? 'Property subscription',
            'unit_range' => $unitRange,
            'activated_on' => optional($subscription?->activated_on)->toDateString(),
            'last_paid_on' => optional($subscription?->last_paid_on)->toDateString(),
            'expired_on' => optional($subscription?->expired_on)->toDateString(),
            'payment_state' => $paymentState,
            'summary_cards' => [
                'subscription_status' => $effectiveStatus,
                'current_period' => $this->formatPeriodLabel($currentPeriodStartsOn, $currentPeriodEndsOn),
                'total_paid_amount_cents' => $totalPaidAmountCents,
                'payments_count' => $paymentsCount,
                'monthly_price_cents' => $monthlyPriceCents,
                'currency' => $currency,
            ],
            'subscription_details' => [
                'billing_profile_name' => $billingProfile?->name ?? 'Property subscription',
                'billing_interval' => $billingProfile?->billing_interval ?? 'monthly',
                'unit_range' => $unitRange,
                'activated_on' => optional($subscription?->activated_on)->toDateString(),
                'last_paid_on' => optional($subscription?->last_paid_on)->toDateString(),
                'expired_on' => optional($subscription?->expired_on)->toDateString(),
            ],
            'next_billing_preview' => $this->nextBillingPreview(),
            'subscription' => $subscription ? [
                'uuid' => $subscription->uuid,
                'status' => $subscription->status,
                'effective_status' => $effectiveStatus,
                'current_period_starts_on' => $currentPeriodStartsOn,
                'current_period_ends_on' => $currentPeriodEndsOn,
                'last_paid_on' => optional($subscription->last_paid_on)->toDateString(),
                'activated_on' => optional($subscription->activated_on)->toDateString(),
                'expired_on' => optional($subscription->expired_on)->toDateString(),
                'billing_rule' => $subscription->billingRule ? [
                    'uuid' => $subscription->billingRule->uuid,
                    'range_start' => (int) $subscription->billingRule->range_start,
                    'range_end' => $subscription->billingRule->range_end !== null ? (int) $subscription->billingRule->range_end : null,
                    'price_cents' => (int) $subscription->billingRule->price_cents,
                    'currency' => $subscription->billingRule->profile?->currency,
                    'billing_profile' => $subscription->billingRule->profile ? [
                        'uuid' => $subscription->billingRule->profile->uuid,
                        'name' => $subscription->billingRule->profile->name,
                        'billing_interval' => $subscription->billingRule->profile->billing_interval,
                    ] : null,
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
                'payments_count' => $paymentsCount,
                'total_paid_amount_cents' => $totalPaidAmountCents,
                'active_payment' => $this->formatPaymentRecord($activePayment),
                'latest_payment' => $this->formatPaymentRecord($latestPayment),
            ],
            'recent_payment_history' => $recentPayments
                ->map(fn ($payment) => $this->formatPaymentRecord($payment))
                ->filter()
                ->values()
                ->all(),
            'property_created_at' => $this->formatTimestamp($this->property_created_at),
            'property_updated_at' => $this->formatTimestamp($this->property_updated_at),
            'property_deleted_at' => $this->formatTimestamp($this->property_deleted_at),
            'last_synced_at' => $this->formatTimestamp($this->last_synced_at),
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
            'payment_date' => optional($payment->payment_date)->toDateString(),
            'coverage_starts_on' => optional($payment->coverage_starts_on)->toDateString(),
            'coverage_ends_on' => optional($payment->coverage_ends_on)->toDateString(),
            'monthly_price_cents' => (int) $payment->monthly_price_cents,
            'total_amount_cents' => (int) $payment->total_amount_cents,
            'currency' => $payment->currency,
            'reference_number' => $payment->reference_number,
            'billing_rule' => $payment->billingRule ? [
                'uuid' => $payment->billingRule->uuid,
                'range_start' => (int) $payment->billingRule->range_start,
                'range_end' => $payment->billingRule->range_end !== null ? (int) $payment->billingRule->range_end : null,
                'price_cents' => (int) $payment->billingRule->price_cents,
                'currency' => $payment->billingRule->profile?->currency ?? $payment->currency,
                'billing_profile' => $payment->billingRule->profile ? [
                    'uuid' => $payment->billingRule->profile->uuid,
                    'name' => $payment->billingRule->profile->name,
                    'billing_interval' => $payment->billingRule->profile->billing_interval,
                ] : null,
            ] : null,
        ];
    }

    private function paymentState(mixed $subscription, string $effectiveStatus): array
    {
        $today = Carbon::today()->toDateString();
        $dueOn = optional($subscription?->current_period_ends_on)->toDateString();

        return match ($effectiveStatus) {
            PropertySubscription::STATUS_ACTIVE => [
                'can_operate_today' => true,
                'payment_required_now' => false,
                'payment_required_reason' => null,
                'payment_due_on' => $dueOn,
                'next_action' => $dueOn !== null && $dueOn < $today ? 'pay_now' : 'wait',
            ],
            PropertySubscription::STATUS_EXPIRED => [
                'can_operate_today' => false,
                'payment_required_now' => true,
                'payment_required_reason' => 'Property subscription has expired.',
                'payment_due_on' => optional($subscription?->current_period_ends_on)->toDateString()
                    ?? optional($subscription?->expired_on)->toDateString(),
                'next_action' => 'pay_now',
            ],
            default => [
                'can_operate_today' => false,
                'payment_required_now' => true,
                'payment_required_reason' => 'Property subscription has not been paid yet.',
                'payment_due_on' => null,
                'next_action' => 'pay_now',
            ],
        };
    }

    private function formatPeriodLabel(?string $startsOn, ?string $endsOn): ?string
    {
        if ($startsOn === null && $endsOn === null) {
            return null;
        }

        if ($startsOn !== null && $endsOn !== null) {
            return $startsOn.' to '.$endsOn;
        }

        return $startsOn ?? $endsOn;
    }

    private function formatUnitRange(?int $rangeStart, ?int $rangeEnd): ?string
    {
        if ($rangeStart === null) {
            return null;
        }

        if ($rangeEnd === null) {
            return $rangeStart.'+';
        }

        return $rangeStart.' - '.$rangeEnd;
    }

    private function nextBillingPreview(): ?array
    {
        $preview = $this->next_billing_preview;

        if (!is_array($preview)) {
            return null;
        }

        return [
            'payment_due_on' => $preview['payment_due_on'] ?? null,
            'current_registered_units_total' => (int) ($preview['current_registered_units_total'] ?? 0),
            'current_monthly_price_cents' => (int) ($preview['current_monthly_price_cents'] ?? 0),
            'projected_monthly_price_cents' => (int) ($preview['projected_monthly_price_cents'] ?? 0),
            'price_change_cents' => (int) ($preview['price_change_cents'] ?? 0),
            'currency' => $preview['currency'] ?? 'TZS',
            'has_price_change' => (bool) ($preview['has_price_change'] ?? false),
            'units_exceed_current_rule' => (bool) ($preview['units_exceed_current_rule'] ?? false),
            'current_billing_rule' => $preview['current_billing_rule'] ?? null,
            'projected_billing_rule' => $preview['projected_billing_rule'] ?? null,
            'message' => $preview['message'] ?? null,
        ];
    }
}
