<?php

namespace App\Http\Resources\Admin\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class SubscriptionUsageAdjustmentResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'reason' => $this->reason,
            'adjustment_type' => $this->adjustment_type,
            'effective_at' => $this->formatTimestamp($this->effective_at),
            'applied_at' => $this->formatTimestamp($this->applied_at),
            'waived_at' => $this->formatTimestamp($this->waived_at),
            'period_starts_at' => $this->formatTimestamp($this->period_starts_at),
            'period_ends_at' => $this->formatTimestamp($this->period_ends_at),
            'total_cycle_days' => (int) $this->total_cycle_days,
            'remaining_cycle_days' => (int) $this->remaining_cycle_days,
            'billing_profile' => $this->billingProfile ? [
                'uuid' => $this->billingProfile->uuid,
                'name' => $this->billingProfile->name,
                'billing_interval' => $this->billingProfile->billing_interval,
                'currency' => $this->billingProfile->currency,
                'status' => $this->billingProfile->status,
            ] : null,
            'baseline' => [
                'properties_count' => (int) $this->baseline_properties_count,
                'registered_units_total' => (int) $this->baseline_registered_units_total,
                'amount_cents' => (int) $this->baseline_amount_cents,
                'frequencies' => $this->baseline_frequencies ?? [],
            ],
            'current' => [
                'properties_count' => (int) $this->current_properties_count,
                'registered_units_total' => (int) $this->current_registered_units_total,
                'amount_cents' => (int) $this->current_amount_cents,
                'frequencies' => $this->current_frequencies ?? [],
            ],
            'pricing' => [
                'delta_price_cents' => (int) $this->delta_price_cents,
                'prorated_adjustment_cents' => (int) $this->prorated_adjustment_cents,
            ],
            ...$this->timestamps(),
        ];
    }
}
