<?php

namespace App\Services\V1;

use App\Models\Landlord\BillingProfile;
use App\Models\Landlord\BillingRule;
use App\Models\Landlord\Subscription;
use App\Models\Tenancy\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class BillingProfileService
{
    /**
     * Handle assign profile to workspace.
     */
    public function assignProfileToWorkspace(Tenant $tenant, BillingProfile $profile): void
    {
        $meta = $tenant->meta ?? [];
        $meta['billing_profile_uuid'] = $profile->uuid;
        $meta['billing_profile_name'] = $profile->name;

        $tenant->meta = $meta;
        $tenant->save();

        $subscription = Subscription::query()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();

        if ($subscription) {
            $subscription->billing_profile_id = $profile->id;

            $subscriptionMeta = $subscription->meta ?? [];
            $subscriptionMeta['billing_profile_uuid'] = $profile->uuid;
            $subscriptionMeta['billing_profile_name'] = $profile->name;
            $subscription->meta = $subscriptionMeta;
            $subscription->save();
        }
    }

    /**
     * Handle matching rule.
     */
    public function matchingRule(BillingProfile $profile, int $registeredUnits, CarbonInterface|string|null $date = null): ?BillingRule
    {
        return $this->matchingRuleFromCollection(
            $this->activeRulesForDate($profile, $date),
            $registeredUnits
        );
    }

    /**
     * Determine whether has overlapping rule.
     */
    public function hasOverlappingRule(BillingProfile $profile, array $payload, ?int $ignoreRuleId = null): bool
    {
        $effectiveFrom = $payload['effective_from'];
        $effectiveTo = $payload['effective_to'] ?? null;

        return BillingRule::query()
            ->where('billing_profile_id', $profile->id)
            ->when($ignoreRuleId !== null, fn ($query) => $query->whereKeyNot($ignoreRuleId))
            ->where('status', 'active')
            ->where(function ($query) use ($effectiveFrom, $effectiveTo) {
                $query->where(function ($inner) use ($effectiveFrom, $effectiveTo) {
                    $inner->where('effective_from', '<=', $effectiveFrom)
                        ->where(function ($dateQuery) use ($effectiveFrom) {
                            $dateQuery->whereNull('effective_to')
                                ->orWhere('effective_to', '>=', $effectiveFrom);
                        });
                })->orWhere(function ($inner) use ($effectiveTo) {
                    if ($effectiveTo === null) {
                        $inner->whereNotNull('id');
                        return;
                    }

                    $inner->where('effective_from', '<=', $effectiveTo)
                        ->where(function ($dateQuery) use ($effectiveTo) {
                            $dateQuery->whereNull('effective_to')
                                ->orWhere('effective_to', '>=', $effectiveTo);
                        });
                });
            })
            ->exists();
    }

    /**
     * Handle active rules for date.
     */
    public function activeRulesForDate(BillingProfile $profile, CarbonInterface|string|null $date = null): Collection
    {
        $date = $date instanceof CarbonInterface ? $date->toDateString() : ($date ?: now()->toDateString());

        return BillingRule::query()
            ->select([
                'id',
                'uuid',
                'tenant_id',
                'billing_profile_id',
                'unit_price_cents',
                'currency',
                'effective_from',
                'effective_to',
                'status',
            ])
            ->where('billing_profile_id', $profile->id)
            ->where('status', 'active')
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Handle matching rule from collection.
     */
    public function matchingRuleFromCollection(Collection $rules, int $registeredUnits): ?BillingRule
    {
        return $rules->first();
    }
}
