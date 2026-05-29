<?php

namespace App\Services\V1;

use App\Models\Landlord\BillingProfile;
use App\Models\Landlord\Subscription;
use App\Models\Landlord\SubscriptionProfileChange;
use App\Models\Tenancy\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SubscriptionBillingProfileChangeService
{
    /** Apply the new billing profile now and calculate a mid-cycle adjustment. */
    public const TIMING_IMMEDIATE_PRORATED = 'immediate_prorated';
    /** Keep the current profile for this cycle and switch when the next cycle starts. */
    public const TIMING_NEXT_CYCLE = 'next_cycle';
    /** A future-dated profile change waiting for its effective time. */
    public const STATUS_PENDING = 'pending';
    /** A profile change that has already been applied to the workspace subscription. */
    public const STATUS_APPLIED = 'applied';
    /** An older pending change replaced by a newer request. */
    public const STATUS_SUPERSEDED = 'superseded';

    public function __construct(
        private SubscriptionService $subscriptionService,
    )
    {
    }

    /** Build a dry-run summary so callers can see pricing and proration before saving a change. */
    public function preview(Tenant $tenant, BillingProfile $newProfile, array $payload = []): array
    {
        $subscription = $this->subscriptionService->currentSubscription($tenant);
        if (!$subscription) {
            throw new InvalidArgumentException('No active subscription record was found for this workspace.');
        }

        $subscription->loadMissing(['billingProfile', 'plan']);

        $timing = $this->normalizeTiming($payload['change_timing'] ?? null);
        $state = $this->subscriptionService->resolveSubscriptionState($subscription);
        $currentProfile = $subscription->billingProfile ?? $this->subscriptionService->resolveWorkspaceBillingProfile($tenant);
        $effectiveAt = $this->resolveEffectiveAt($payload['effective_at'] ?? null, $timing, $state);
        $frequencies = $this->subscriptionService->getWorkspacePropertyUsageFrequencies($tenant);
        $currentAmount = $this->subscriptionService->estimateTotalPriceFromFrequencies(
            $frequencies,
            $currentProfile,
            $effectiveAt
        );
        $newAmount = $this->subscriptionService->estimateTotalPriceFromFrequencies(
            $frequencies,
            $newProfile,
            $effectiveAt
        );

        $proration = $this->calculateProration($subscription, $state, $timing, $effectiveAt, $currentAmount, $newAmount);
        $pendingChange = $this->pendingChangeForSubscription($subscription);

        return [
            'workspace_uuid' => $tenant->uuid,
            'subscription_uuid' => $subscription->uuid,
            'subscription_status' => $subscription->status,
            'change_timing' => $timing,
            'effective_at' => $effectiveAt->format('Y-m-d H:i:s'),
            'current_period_starts_at' => $this->formatDateTime($state['period_starts_at']),
            'current_period_ends_at' => $this->formatDateTime($state['period_ends_at']),
            'current_billing_profile' => $this->formatProfile($currentProfile),
            'new_billing_profile' => $this->formatProfile($newProfile),
            'pending_change' => $pendingChange ? $this->formatChange($pendingChange) : null,
            'pricing' => [
                'current_estimated_price_cents' => $currentAmount,
                'new_estimated_price_cents' => $newAmount,
                'delta_price_cents' => $newAmount - $currentAmount,
            ],
            'proration' => $proration,
        ];
    }

    /** Persist a billing profile change and apply it immediately or schedule it for the next cycle. */
    public function apply(Tenant $tenant, BillingProfile $newProfile, array $payload = []): Subscription
    {
        return DB::connection('base')->transaction(function () use ($tenant, $newProfile, $payload) {
            Tenant::query()
                ->whereKey($tenant->id)
                ->lockForUpdate()
                ->first();

            $subscription = Subscription::query()
                ->with(['billingProfile', 'plan'])
                ->where('tenant_id', $tenant->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                throw new InvalidArgumentException('No active subscription record was found for this workspace.');
            }

            $preview = $this->preview($tenant->fresh(), $newProfile, $payload);
            $timing = $preview['change_timing'];

            $this->supersedePendingChanges($subscription);

            $change = SubscriptionProfileChange::query()->create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'old_billing_profile_id' => $subscription->billing_profile_id,
                'new_billing_profile_id' => $newProfile->id,
                'change_timing' => $timing,
                'status' => $timing === self::TIMING_IMMEDIATE_PRORATED ? self::STATUS_APPLIED : self::STATUS_PENDING,
                'effective_at' => $preview['effective_at'],
                'applied_at' => $timing === self::TIMING_IMMEDIATE_PRORATED ? now() : null,
                'period_starts_at' => $preview['current_period_starts_at'],
                'period_ends_at' => $preview['current_period_ends_at'],
                'total_cycle_days' => $preview['proration']['total_cycle_days'],
                'remaining_cycle_days' => $preview['proration']['remaining_cycle_days'],
                'current_price_cents' => $preview['pricing']['current_estimated_price_cents'],
                'new_price_cents' => $preview['pricing']['new_estimated_price_cents'],
                'prorated_adjustment_cents' => $preview['proration']['prorated_adjustment_cents'],
                'meta' => [
                    'pricing_delta_cents' => $preview['pricing']['delta_price_cents'],
                    'proration_applies' => $preview['proration']['applies'],
                ],
            ]);

            if ($timing === self::TIMING_IMMEDIATE_PRORATED) {
                $this->applyProfileToWorkspace($tenant, $subscription, $newProfile);
            }

            return $subscription->fresh(['plan', 'billingProfile']);
        });
    }

    /** Promote a pending next-cycle change once its effective timestamp has arrived. */
    public function applyDuePendingChangeIfNeeded(Tenant $tenant): ?SubscriptionProfileChange
    {
        return DB::connection('base')->transaction(function () use ($tenant) {
            $subscription = Subscription::query()
                ->with(['billingProfile', 'plan'])
                ->where('tenant_id', $tenant->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                return null;
            }

            $change = SubscriptionProfileChange::query()
                ->with('newBillingProfile')
                ->where('subscription_id', $subscription->id)
                ->where('status', self::STATUS_PENDING)
                ->where('effective_at', '<=', now())
                ->orderBy('effective_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$change || !$change->newBillingProfile) {
                return null;
            }

            $this->applyProfileToWorkspace($tenant, $subscription, $change->newBillingProfile);

            $change->status = self::STATUS_APPLIED;
            $change->applied_at = now();
            $change->save();

            return $change->fresh(['oldBillingProfile', 'newBillingProfile']);
        });
    }

    /** Return the latest pending change so summary endpoints can show what will happen next. */
    public function pendingChangeForSubscription(Subscription $subscription): ?SubscriptionProfileChange
    {
        return SubscriptionProfileChange::query()
            ->with(['oldBillingProfile:id,uuid,name,billing_interval,currency', 'newBillingProfile:id,uuid,name,billing_interval,currency'])
            ->where('subscription_id', $subscription->id)
            ->where('status', self::STATUS_PENDING)
            ->orderBy('effective_at')
            ->orderByDesc('id')
            ->first();
    }

    /** Retire older pending changes so one subscription has a single source of future truth. */
    private function supersedePendingChanges(Subscription $subscription): void
    {
        SubscriptionProfileChange::query()
            ->where('subscription_id', $subscription->id)
            ->where('status', self::STATUS_PENDING)
            ->update(['status' => self::STATUS_SUPERSEDED, 'updated_at' => now()]);
    }

    /** Write the new billing profile to both tenant metadata and the live subscription record. */
    private function applyProfileToWorkspace(Tenant $tenant, Subscription $subscription, BillingProfile $profile): void
    {
        $tenantMeta = $tenant->meta ?? [];
        $tenantMeta['billing_profile_uuid'] = $profile->uuid;
        $tenantMeta['billing_profile_name'] = $profile->name;
        $tenant->meta = $tenantMeta;
        $tenant->save();

        $subscription->billing_profile_id = $profile->id;
        $subscriptionMeta = $subscription->meta ?? [];
        $subscriptionMeta['billing_profile_uuid'] = $profile->uuid;
        $subscriptionMeta['billing_profile_name'] = $profile->name;
        $subscription->meta = $subscriptionMeta;
        $subscription->save();
    }

    /** Calculate the immediate mid-cycle charge or credit using inclusive remaining-day proration. */
    private function calculateProration(
        Subscription $subscription,
        array $state,
        string $timing,
        Carbon $effectiveAt,
        int $currentAmount,
        int $newAmount,
    ): array {
        $periodStartsAt = $state['period_starts_at'];
        $periodEndsAt = $state['period_ends_at'];
        $applies = $subscription->status === 'active'
            && $timing === self::TIMING_IMMEDIATE_PRORATED
            && $state['is_current_period_active']
            && $periodStartsAt instanceof CarbonInterface
            && $periodEndsAt instanceof CarbonInterface;

        if (!$applies) {
            return [
                'applies' => false,
                'total_cycle_days' => 0,
                'remaining_cycle_days' => 0,
                'prorated_adjustment_cents' => 0,
                'adjustment_type' => 'none',
            ];
        }

        $periodStart = Carbon::parse($periodStartsAt)->startOfDay();
        $periodEnd = Carbon::parse($periodEndsAt)->endOfDay();
        $anchor = $effectiveAt->copy()->lt($periodStart) ? $periodStart : $effectiveAt->copy();

        if ($anchor->gt($periodEnd)) {
            throw new InvalidArgumentException('Immediate proration must be applied within the current billing cycle.');
        }

        $totalCycleDays = $periodStart->diffInDays($periodEnd->copy()->startOfDay()) + 1;
        $remainingCycleDays = $anchor->copy()->startOfDay()->diffInDays($periodEnd->copy()->startOfDay()) + 1;
        $adjustment = (int) round((($newAmount - $currentAmount) * $remainingCycleDays) / max($totalCycleDays, 1));

        return [
            'applies' => true,
            'total_cycle_days' => $totalCycleDays,
            'remaining_cycle_days' => $remainingCycleDays,
            'prorated_adjustment_cents' => $adjustment,
            'adjustment_type' => $adjustment > 0 ? 'charge' : ($adjustment < 0 ? 'credit' : 'none'),
        ];
    }

    /** Enforce the supported change timing options before any billing workflow runs. */
    private function normalizeTiming(?string $timing): string
    {
        $timing = $timing ?: self::TIMING_IMMEDIATE_PRORATED;

        if (!in_array($timing, [self::TIMING_IMMEDIATE_PRORATED, self::TIMING_NEXT_CYCLE], true)) {
            throw new InvalidArgumentException('Unsupported billing profile change timing was provided.');
        }

        return $timing;
    }

    /** Resolve when the new billing profile should take effect for immediate and scheduled changes. */
    private function resolveEffectiveAt(?string $effectiveAt, string $timing, array $state): Carbon
    {
        if ($timing === self::TIMING_NEXT_CYCLE) {
            $periodEndsAt = $state['period_ends_at'];

            if ($periodEndsAt instanceof CarbonInterface) {
                return Carbon::parse($periodEndsAt)->addSecond();
            }

            return now();
        }

        return $effectiveAt ? Carbon::parse($effectiveAt) : now();
    }

    /** Shape a billing profile into a lightweight response payload for previews and summaries. */
    private function formatProfile(?BillingProfile $profile): ?array
    {
        if (!$profile) {
            return null;
        }

        return [
            'uuid' => $profile->uuid,
            'name' => $profile->name,
            'billing_interval' => $profile->billing_interval,
            'currency' => $profile->currency,
            'status' => $profile->status,
        ];
    }

    /** Shape a stored change record into a stable API payload for pending/applied change displays. */
    private function formatChange(SubscriptionProfileChange $change): array
    {
        return [
            'uuid' => $change->uuid,
            'status' => $change->status,
            'change_timing' => $change->change_timing,
            'effective_at' => $this->formatDateTime($change->effective_at),
            'applied_at' => $this->formatDateTime($change->applied_at),
            'current_price_cents' => $change->current_price_cents,
            'new_price_cents' => $change->new_price_cents,
            'prorated_adjustment_cents' => $change->prorated_adjustment_cents,
            'old_billing_profile' => $this->formatProfile($change->oldBillingProfile),
            'new_billing_profile' => $this->formatProfile($change->newBillingProfile),
        ];
    }

    /** Keep service responses on the same datetime format used by the rest of the billing APIs. */
    private function formatDateTime(CarbonInterface|string|null $value): ?string
    {
        if (!$value) {
            return null;
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }
}
