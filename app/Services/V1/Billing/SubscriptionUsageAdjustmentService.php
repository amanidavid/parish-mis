<?php

namespace App\Services\V1\Billing;

use App\Models\Landlord\BillingProfile;
use App\Models\Landlord\Subscription;
use App\Models\Landlord\SubscriptionUsageAdjustment;
use App\Models\Landlord\SubscriptionUsageBaseline;
use App\Models\Tenancy\Tenant;
use App\Services\V1\SubscriptionService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SubscriptionUsageAdjustmentService
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private BillingProrationService $billingProrationService,
    ) {
    }

    public function prepareInventoryMutation(Tenant $tenant): void
    {
        $context = $this->resolveBillableContext($tenant);

        if ($context === null) {
            return;
        }

        $this->ensureCurrentCycleBaseline(
            $tenant,
            $context['subscription'],
            $context['billing_profile'],
            $context['subscription_state']
        );
    }

    public function previewCurrentAdjustment(Tenant $tenant): array
    {
        $subscription = $this->subscriptionService->currentSubscription($tenant);
        $subscriptionState = $this->subscriptionService->resolveSubscriptionState($subscription);
        $pendingAdjustment = $subscription
            ? $this->pendingAdjustmentForSubscription($subscription, $subscriptionState['period_starts_at'] ?? null)
            : null;

        if (!$subscription) {
            return $this->emptyPreview($tenant, null, null, $subscriptionState, $pendingAdjustment, 'No active subscription record was found for this workspace.');
        }

        $billingProfile = $subscription->billingProfile ?? $this->subscriptionService->resolveWorkspaceBillingProfile($tenant);

        if (!$billingProfile) {
            return $this->emptyPreview($tenant, $subscription, null, $subscriptionState, $pendingAdjustment, 'No billing profile is configured for this workspace.');
        }

        if ($subscription->status !== 'active' || !$subscriptionState['is_current_period_active']) {
            return $this->emptyPreview(
                $tenant,
                $subscription,
                $billingProfile,
                $subscriptionState,
                $pendingAdjustment,
                'Automatic usage adjustments apply only during an active billing cycle.'
            );
        }

        $baseline = $this->ensureCurrentCycleBaseline($tenant, $subscription, $billingProfile, $subscriptionState);
        $effectiveAt = now();
        $currentFrequencies = $this->normalizeFrequencies(
            $this->subscriptionService->getWorkspacePropertyUsageFrequencies($tenant)
        );
        $currentSummary = $this->summarizeFrequencies($currentFrequencies);
        $currentAmount = $this->subscriptionService->estimateTotalPriceFromFrequencies(
            collect($currentFrequencies)->map(fn (array $row) => (object) $row),
            $billingProfile,
            $effectiveAt
        );
        $proration = $this->billingProrationService->calculateCurrentCycleAdjustment(
            $subscriptionState['period_starts_at'] ?? null,
            $subscriptionState['period_ends_at'] ?? null,
            (bool) ($subscriptionState['is_current_period_active'] ?? false),
            $subscription->status === 'active',
            $effectiveAt,
            (int) $baseline->total_price_cents,
            $currentAmount,
        );
        $deltaPrice = $currentAmount - (int) $baseline->total_price_cents;
        $hasBillableAdjustment = $proration['applies']
            && $deltaPrice !== 0
            && (int) $proration['prorated_adjustment_cents'] !== 0;

        return [
            'workspace_uuid' => $tenant->uuid,
            'subscription_uuid' => $subscription->uuid,
            'subscription_status' => $subscription->status,
            'billing_profile' => $this->formatBillingProfile($billingProfile),
            'eligibility' => [
                'is_billable_cycle' => true,
                'reason' => null,
                'has_billable_adjustment' => $hasBillableAdjustment,
            ],
            'effective_at' => $effectiveAt->format('Y-m-d H:i:s'),
            'period_starts_at' => $this->formatDateTime($subscriptionState['period_starts_at'] ?? null),
            'period_ends_at' => $this->formatDateTime($subscriptionState['period_ends_at'] ?? null),
            'baseline' => [
                'uuid' => $baseline->uuid,
                'accounted_at' => $this->formatDateTime($baseline->accounted_at),
                'properties_count' => (int) $baseline->total_properties,
                'registered_units_total' => (int) $baseline->registered_units_total,
                'amount_cents' => (int) $baseline->total_price_cents,
                'frequencies' => $baseline->frequencies ?? [],
            ],
            'current' => [
                'properties_count' => $currentSummary['properties_count'],
                'registered_units_total' => $currentSummary['registered_units_total'],
                'amount_cents' => $currentAmount,
                'frequencies' => $currentFrequencies,
            ],
            'pricing' => [
                'delta_price_cents' => $deltaPrice,
            ],
            'proration' => $proration,
            'pending_adjustment' => $pendingAdjustment ? $this->formatAdjustmentSummary($pendingAdjustment) : null,
        ];
    }

    public function syncPendingAdjustment(Tenant $tenant): ?SubscriptionUsageAdjustment
    {
        $preview = $this->previewCurrentAdjustment($tenant);
        $subscriptionUuid = $preview['subscription_uuid'] ?? null;

        if (!$subscriptionUuid) {
            return null;
        }

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()
            ->where('uuid', $subscriptionUuid)
            ->first();

        if (!$subscription) {
            return null;
        }

        $periodStartsAt = $preview['period_starts_at'] ?? null;
        $existingPending = $this->pendingAdjustmentForSubscription(
            $subscription,
            $periodStartsAt ? Carbon::parse($periodStartsAt) : null
        );

        if (!(bool) data_get($preview, 'eligibility.has_billable_adjustment', false)) {
            if ($existingPending) {
                $this->supersedeAdjustments($subscription->id, $periodStartsAt ? Carbon::parse($periodStartsAt) : null);
            }

            return null;
        }

        if ($existingPending && $this->matchesPreview($existingPending, $preview)) {
            return $existingPending->fresh(['billingProfile']);
        }

        $periodStart = Carbon::parse($preview['period_starts_at']);

        return DB::connection('base')->transaction(function () use ($tenant, $subscription, $preview, $periodStart) {
            $this->supersedeAdjustments($subscription->id, $periodStart);

            return SubscriptionUsageAdjustment::query()->create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'billing_profile_id' => $this->resolveBillingProfileId($preview['billing_profile'] ?? null),
                'reason' => SubscriptionUsageAdjustment::REASON_USAGE_CHANGE,
                'status' => SubscriptionUsageAdjustment::STATUS_PENDING,
                'adjustment_type' => data_get($preview, 'proration.adjustment_type', SubscriptionUsageAdjustment::TYPE_NONE),
                'effective_at' => $preview['effective_at'],
                'period_starts_at' => $preview['period_starts_at'],
                'period_ends_at' => $preview['period_ends_at'],
                'total_cycle_days' => (int) data_get($preview, 'proration.total_cycle_days', 0),
                'remaining_cycle_days' => (int) data_get($preview, 'proration.remaining_cycle_days', 0),
                'baseline_properties_count' => (int) data_get($preview, 'baseline.properties_count', 0),
                'current_properties_count' => (int) data_get($preview, 'current.properties_count', 0),
                'baseline_registered_units_total' => (int) data_get($preview, 'baseline.registered_units_total', 0),
                'current_registered_units_total' => (int) data_get($preview, 'current.registered_units_total', 0),
                'baseline_amount_cents' => (int) data_get($preview, 'baseline.amount_cents', 0),
                'current_amount_cents' => (int) data_get($preview, 'current.amount_cents', 0),
                'delta_price_cents' => (int) data_get($preview, 'pricing.delta_price_cents', 0),
                'prorated_adjustment_cents' => (int) data_get($preview, 'proration.prorated_adjustment_cents', 0),
                'baseline_frequencies' => data_get($preview, 'baseline.frequencies', []),
                'current_frequencies' => data_get($preview, 'current.frequencies', []),
                'meta' => [
                    'billing_profile' => $preview['billing_profile'] ?? null,
                ],
            ])->loadMissing('billingProfile');
        });
    }

    public function listAdjustments(Tenant $tenant, array $filters = []): LengthAwarePaginator
    {
        $query = SubscriptionUsageAdjustment::query()
            ->with(['billingProfile:id,uuid,name,billing_interval,currency,status'])
            ->where('tenant_id', $tenant->id);

        if (!empty($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['adjustment_type'] ?? null)) {
            $query->where('adjustment_type', $filters['adjustment_type']);
        }

        $sort = trim((string) ($filters['sort'] ?? '-created_at'));
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        match ($column) {
            'effective_at' => $query->orderBy('effective_at', $direction)->orderByDesc('created_at'),
            'status' => $query->orderBy('status', $direction)->orderByDesc('created_at'),
            'adjustment_type' => $query->orderBy('adjustment_type', $direction)->orderByDesc('created_at'),
            'prorated_adjustment_cents' => $query->orderBy('prorated_adjustment_cents', $direction)->orderByDesc('created_at'),
            default => $query->orderBy('created_at', $direction),
        };

        return $query
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();
    }

    public function applyAdjustment(Tenant $tenant, SubscriptionUsageAdjustment $adjustment): SubscriptionUsageAdjustment
    {
        return DB::connection('base')->transaction(function () use ($tenant, $adjustment) {
            $lockedAdjustment = SubscriptionUsageAdjustment::query()
                ->with(['billingProfile:id,uuid,name,billing_interval,currency,status'])
                ->whereKey($adjustment->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedAdjustment || $lockedAdjustment->tenant_id !== $tenant->id) {
                throw new InvalidArgumentException('The selected usage adjustment could not be found for this workspace.');
            }

            if ($lockedAdjustment->status !== SubscriptionUsageAdjustment::STATUS_PENDING) {
                throw new InvalidArgumentException('Only pending usage adjustments can be applied.');
            }

            $baseline = $this->baselineForPeriod(
                $lockedAdjustment->subscription_id,
                Carbon::parse($lockedAdjustment->period_starts_at)
            );

            if (!$baseline) {
                throw new InvalidArgumentException('The current billing baseline could not be resolved for this workspace.');
            }

            $this->advanceBaseline($baseline, $lockedAdjustment);

            $lockedAdjustment->status = SubscriptionUsageAdjustment::STATUS_APPLIED;
            $lockedAdjustment->applied_at = now();
            $lockedAdjustment->save();

            return $lockedAdjustment->fresh(['billingProfile']);
        });
    }

    public function waiveAdjustment(Tenant $tenant, SubscriptionUsageAdjustment $adjustment): SubscriptionUsageAdjustment
    {
        return DB::connection('base')->transaction(function () use ($tenant, $adjustment) {
            $lockedAdjustment = SubscriptionUsageAdjustment::query()
                ->with(['billingProfile:id,uuid,name,billing_interval,currency,status'])
                ->whereKey($adjustment->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedAdjustment || $lockedAdjustment->tenant_id !== $tenant->id) {
                throw new InvalidArgumentException('The selected usage adjustment could not be found for this workspace.');
            }

            if ($lockedAdjustment->status !== SubscriptionUsageAdjustment::STATUS_PENDING) {
                throw new InvalidArgumentException('Only pending usage adjustments can be waived.');
            }

            $baseline = $this->baselineForPeriod(
                $lockedAdjustment->subscription_id,
                Carbon::parse($lockedAdjustment->period_starts_at)
            );

            if (!$baseline) {
                throw new InvalidArgumentException('The current billing baseline could not be resolved for this workspace.');
            }

            $this->advanceBaseline($baseline, $lockedAdjustment);

            $lockedAdjustment->status = SubscriptionUsageAdjustment::STATUS_WAIVED;
            $lockedAdjustment->waived_at = now();
            $lockedAdjustment->save();

            return $lockedAdjustment->fresh(['billingProfile']);
        });
    }

    public function resetCurrentCycleBaselineToCurrentUsage(Tenant $tenant): ?SubscriptionUsageBaseline
    {
        $context = $this->resolveBillableContext($tenant);

        if ($context === null) {
            return null;
        }

        $frequencies = $this->normalizeFrequencies(
            $this->subscriptionService->getWorkspacePropertyUsageFrequencies($tenant)
        );
        $summary = $this->summarizeFrequencies($frequencies);
        $currentAmount = $this->subscriptionService->estimateTotalPriceFromFrequencies(
            collect($frequencies)->map(fn (array $row) => (object) $row),
            $context['billing_profile'],
            now()
        );

        $baseline = $this->ensureCurrentCycleBaseline(
            $tenant,
            $context['subscription'],
            $context['billing_profile'],
            $context['subscription_state']
        );

        DB::connection('base')->transaction(function () use ($baseline, $context, $summary, $frequencies, $currentAmount) {
            $baseline->fill([
                'billing_profile_id' => $context['billing_profile']->id,
                'period_ends_at' => $context['subscription_state']['period_ends_at'],
                'accounted_at' => now(),
                'total_properties' => $summary['properties_count'],
                'registered_units_total' => $summary['registered_units_total'],
                'total_price_cents' => $currentAmount,
                'frequencies' => $frequencies,
            ])->save();

            $this->supersedeAdjustments(
                $context['subscription']->id,
                Carbon::parse($context['subscription_state']['period_starts_at'])
            );
        });

        return $baseline->fresh();
    }

    public function pendingAdjustmentForSubscription(
        Subscription $subscription,
        CarbonInterface|string|null $periodStartsAt = null
    ): ?SubscriptionUsageAdjustment {
        $query = SubscriptionUsageAdjustment::query()
            ->with(['billingProfile:id,uuid,name,billing_interval,currency,status'])
            ->where('subscription_id', $subscription->id)
            ->where('status', SubscriptionUsageAdjustment::STATUS_PENDING);

        if ($periodStartsAt !== null) {
            $query->where('period_starts_at', Carbon::parse($periodStartsAt)->format('Y-m-d H:i:s'));
        }

        return $query
            ->orderByDesc('created_at')
            ->first();
    }

    private function resolveBillableContext(Tenant $tenant): ?array
    {
        $subscription = $this->subscriptionService->currentSubscription($tenant);

        if (!$subscription) {
            return null;
        }

        $subscriptionState = $this->subscriptionService->resolveSubscriptionState($subscription);
        $billingProfile = $subscription->billingProfile ?? $this->subscriptionService->resolveWorkspaceBillingProfile($tenant);

        if (!$billingProfile
            || $subscription->status !== 'active'
            || !($subscriptionState['is_current_period_active'] ?? false)
            || !(($subscriptionState['period_starts_at'] ?? null) instanceof CarbonInterface)
            || !(($subscriptionState['period_ends_at'] ?? null) instanceof CarbonInterface)) {
            return null;
        }

        return [
            'subscription' => $subscription,
            'subscription_state' => $subscriptionState,
            'billing_profile' => $billingProfile,
        ];
    }

    private function ensureCurrentCycleBaseline(
        Tenant $tenant,
        Subscription $subscription,
        BillingProfile $billingProfile,
        array $subscriptionState
    ): SubscriptionUsageBaseline {
        $periodStartsAt = Carbon::parse($subscriptionState['period_starts_at'])->format('Y-m-d H:i:s');
        $existing = $this->baselineForPeriod($subscription->id, $periodStartsAt);

        if ($existing) {
            return $existing;
        }

        $frequencies = $this->normalizeFrequencies(
            $this->subscriptionService->getWorkspacePropertyUsageFrequencies($tenant)
        );
        $summary = $this->summarizeFrequencies($frequencies);
        $totalPrice = $this->subscriptionService->estimateTotalPriceFromFrequencies(
            collect($frequencies)->map(fn (array $row) => (object) $row),
            $billingProfile,
            $subscriptionState['period_starts_at']
        );

        return DB::connection('base')->transaction(function () use ($tenant, $subscription, $billingProfile, $subscriptionState, $periodStartsAt, $frequencies, $summary, $totalPrice) {
            $lockedExisting = $this->baselineForPeriod($subscription->id, $periodStartsAt, true);

            if ($lockedExisting) {
                return $lockedExisting;
            }

            return SubscriptionUsageBaseline::query()->create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'billing_profile_id' => $billingProfile->id,
                'period_starts_at' => $periodStartsAt,
                'period_ends_at' => Carbon::parse($subscriptionState['period_ends_at'])->format('Y-m-d H:i:s'),
                'accounted_at' => now(),
                'total_properties' => $summary['properties_count'],
                'registered_units_total' => $summary['registered_units_total'],
                'total_price_cents' => $totalPrice,
                'frequencies' => $frequencies,
            ]);
        });
    }

    private function baselineForPeriod(
        int $subscriptionId,
        CarbonInterface|string $periodStartsAt,
        bool $lockForUpdate = false
    ): ?SubscriptionUsageBaseline {
        $query = SubscriptionUsageBaseline::query()
            ->where('subscription_id', $subscriptionId)
            ->where('period_starts_at', Carbon::parse($periodStartsAt)->format('Y-m-d H:i:s'));

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function supersedeAdjustments(int $subscriptionId, ?CarbonInterface $periodStartsAt): void
    {
        if (!$periodStartsAt) {
            return;
        }

        SubscriptionUsageAdjustment::query()
            ->where('subscription_id', $subscriptionId)
            ->where('period_starts_at', $periodStartsAt->format('Y-m-d H:i:s'))
            ->where('status', SubscriptionUsageAdjustment::STATUS_PENDING)
            ->update([
                'status' => SubscriptionUsageAdjustment::STATUS_SUPERSEDED,
                'updated_at' => now(),
            ]);
    }

    private function advanceBaseline(
        SubscriptionUsageBaseline $baseline,
        SubscriptionUsageAdjustment $adjustment
    ): void {
        $baseline->fill([
            'billing_profile_id' => $adjustment->billing_profile_id,
            'period_ends_at' => $adjustment->period_ends_at,
            'accounted_at' => now(),
            'total_properties' => $adjustment->current_properties_count,
            'registered_units_total' => $adjustment->current_registered_units_total,
            'total_price_cents' => $adjustment->current_amount_cents,
            'frequencies' => $adjustment->current_frequencies,
        ])->save();
    }

    private function summarizeFrequencies(array $frequencies): array
    {
        $propertiesCount = 0;
        $registeredUnitsTotal = 0;

        foreach ($frequencies as $frequency) {
            $properties = (int) ($frequency['properties_count'] ?? 0);
            $units = (int) ($frequency['registered_units'] ?? 0);
            $propertiesCount += $properties;
            $registeredUnitsTotal += $properties * $units;
        }

        return [
            'properties_count' => $propertiesCount,
            'registered_units_total' => $registeredUnitsTotal,
        ];
    }

    private function normalizeFrequencies(Collection $frequencies): array
    {
        return $frequencies
            ->map(fn (object $row) => [
                'registered_units' => (int) $row->registered_units,
                'properties_count' => (int) $row->properties_count,
            ])
            ->sortBy('registered_units')
            ->values()
            ->all();
    }

    private function emptyPreview(
        Tenant $tenant,
        ?Subscription $subscription,
        ?BillingProfile $billingProfile,
        array $subscriptionState,
        ?SubscriptionUsageAdjustment $pendingAdjustment,
        string $reason
    ): array {
        return [
            'workspace_uuid' => $tenant->uuid,
            'subscription_uuid' => $subscription?->uuid,
            'subscription_status' => $subscription?->status,
            'billing_profile' => $this->formatBillingProfile($billingProfile),
            'eligibility' => [
                'is_billable_cycle' => false,
                'reason' => $reason,
                'has_billable_adjustment' => false,
            ],
            'effective_at' => now()->format('Y-m-d H:i:s'),
            'period_starts_at' => $this->formatDateTime($subscriptionState['period_starts_at'] ?? null),
            'period_ends_at' => $this->formatDateTime($subscriptionState['period_ends_at'] ?? null),
            'baseline' => null,
            'current' => null,
            'pricing' => [
                'delta_price_cents' => 0,
            ],
            'proration' => $this->billingProrationService->emptyAdjustment(),
            'pending_adjustment' => $pendingAdjustment ? $this->formatAdjustmentSummary($pendingAdjustment) : null,
        ];
    }

    private function resolveBillingProfileId(?array $billingProfile): ?int
    {
        $uuid = $billingProfile['uuid'] ?? null;

        if (!$uuid) {
            return null;
        }

        return BillingProfile::query()
            ->where('uuid', $uuid)
            ->value('id');
    }

    private function matchesPreview(SubscriptionUsageAdjustment $adjustment, array $preview): bool
    {
        return (int) $adjustment->baseline_amount_cents === (int) data_get($preview, 'baseline.amount_cents', 0)
            && (int) $adjustment->current_amount_cents === (int) data_get($preview, 'current.amount_cents', 0)
            && (int) $adjustment->baseline_registered_units_total === (int) data_get($preview, 'baseline.registered_units_total', 0)
            && (int) $adjustment->current_registered_units_total === (int) data_get($preview, 'current.registered_units_total', 0)
            && (int) $adjustment->prorated_adjustment_cents === (int) data_get($preview, 'proration.prorated_adjustment_cents', 0)
            && (array) ($adjustment->current_frequencies ?? []) === (array) data_get($preview, 'current.frequencies', []);
    }

    private function formatBillingProfile(?BillingProfile $billingProfile): ?array
    {
        if (!$billingProfile) {
            return null;
        }

        return [
            'uuid' => $billingProfile->uuid,
            'name' => $billingProfile->name,
            'billing_interval' => $billingProfile->billing_interval,
            'currency' => $billingProfile->currency,
            'status' => $billingProfile->status,
        ];
    }

    private function formatAdjustmentSummary(SubscriptionUsageAdjustment $adjustment): array
    {
        return [
            'uuid' => $adjustment->uuid,
            'status' => $adjustment->status,
            'adjustment_type' => $adjustment->adjustment_type,
            'effective_at' => $this->formatDateTime($adjustment->effective_at),
            'applied_at' => $this->formatDateTime($adjustment->applied_at),
            'waived_at' => $this->formatDateTime($adjustment->waived_at),
            'prorated_adjustment_cents' => (int) $adjustment->prorated_adjustment_cents,
            'delta_price_cents' => (int) $adjustment->delta_price_cents,
        ];
    }

    private function formatDateTime(CarbonInterface|string|null $value): ?string
    {
        if (!$value) {
            return null;
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }
}
