<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class WorkspacePropertySubscriptionPaymentResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'months_paid' => (int) $this->months_paid,
            'rule_range_start' => (int) $this->rule_range_start,
            'rule_range_end' => $this->rule_range_end !== null ? (int) $this->rule_range_end : null,
            'monthly_price_cents' => (int) $this->monthly_price_cents,
            'total_amount_cents' => (int) $this->total_amount_cents,
            'currency' => $this->currency,
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
                'range_start' => (int) $this->billingRule->range_start,
                'range_end' => $this->billingRule->range_end !== null ? (int) $this->billingRule->range_end : null,
                'price_cents' => (int) $this->billingRule->price_cents,
                'currency' => $this->billingRule->profile?->currency ?? $this->currency,
                'billing_profile' => $this->billingRule->profile ? [
                    'uuid' => $this->billingRule->profile->uuid,
                    'name' => $this->billingRule->profile->name,
                    'billing_interval' => $this->billingRule->profile->billing_interval,
                ] : null,
            ] : null,
            ...$this->timestamps(),
        ];
    }
}
