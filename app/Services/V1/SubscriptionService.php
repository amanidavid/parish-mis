<?php

namespace App\Services\V1;

use App\Models\Landlord\BillingProfile;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Subscription;
use App\Models\Landlord\SubscriptionUsage;
use App\Models\Tenant\Property;
use App\Models\Tenant\Unit;
use App\Models\Tenancy\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionService
{
    private const METRIC_PROPERTIES = 'properties';
    private const METRIC_REGISTERED_UNITS_TOTAL = 'registered_units_total';
    private const OPEN_SUBSCRIPTION_STATUSES = ['trialing', 'active'];

    public function __construct(private BillingProfileService $billingProfileService)
    {
    }

    public function createTrialSubscriptionForTenant(Tenant $tenant, ?string $planUuid = null): Subscription
    {
        $plan = $this->resolveOnboardingPlan($planUuid);
        $billingProfile = $this->resolveWorkspaceBillingProfile($tenant);
        $startsAt = now()->startOfDay();
        $trialEndsAt = $this->calculateTrialEndsAt($startsAt, (int) $plan->trial_days);

        return DB::connection('base')->transaction(function () use ($tenant, $plan, $billingProfile, $startsAt, $trialEndsAt) {
            Tenant::query()
                ->whereKey($tenant->id)
                ->lockForUpdate()
                ->first();

            $existingSubscription = Subscription::query()
                ->with(['plan', 'billingProfile'])
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', self::OPEN_SUBSCRIPTION_STATUSES)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($existingSubscription) {
                return $existingSubscription;
            }

            $subscription = Subscription::query()->create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'billing_profile_id' => $billingProfile?->id,
                'status' => $trialEndsAt->greaterThan(now()) ? 'trialing' : 'past_due',
                'starts_at' => $startsAt,
                'ends_at' => $trialEndsAt,
                'trial_ends_at' => $trialEndsAt,
                'meta' => [
                    'plan_name' => $plan->name,
                    'properties_included' => $plan->properties_included,
                    'price_per_property_cents' => $plan->price_per_property_cents,
                    'billing_profile_uuid' => $billingProfile?->uuid,
                    'billing_profile_name' => $billingProfile?->name,
                    'features' => $plan->features ?? [],
                ],
            ]);

            $this->updateUsageMetric(
                $tenant->id,
                self::METRIC_PROPERTIES,
                0,
                $startsAt->copy(),
                [
                    'properties_included' => $plan->properties_included,
                    'price_per_property_cents' => $plan->price_per_property_cents,
                ]
            );

            $this->updateUsageMetric(
                $tenant->id,
                self::METRIC_REGISTERED_UNITS_TOTAL,
                0,
                $startsAt->copy(),
                [
                    'pricing_model' => 'registered_units_total',
                ]
            );

            return $subscription->loadMissing(['plan', 'billingProfile']);
        });
    }

    public function syncWorkspaceUsage(Tenant $tenant): array
    {
        $counts = $this->runInTenantContext($tenant, fn () => $this->calculateWorkspaceInventoryTotals());
        $now = now();

        $this->updateUsageMetric($tenant->id, self::METRIC_PROPERTIES, $counts['total_properties'], $now, [
            'pricing_model' => 'property_count',
        ]);

        $this->updateUsageMetric($tenant->id, self::METRIC_REGISTERED_UNITS_TOTAL, $counts['total_units'], $now, [
            'pricing_model' => 'registered_units_total',
        ]);

        return $counts;
    }

    public function getWorkspaceSubscriptionSummary(Tenant $tenant): array
    {
        $subscription = $this->currentSubscription($tenant);
        $billingProfile = $subscription?->billingProfile ?? $this->resolveWorkspaceBillingProfile($tenant);
        $summaryUsage = $this->runInTenantContext($tenant, function () use ($billingProfile) {
            $counts = $this->calculateWorkspaceInventoryTotals();

            return [
                ...$counts,
                'estimated_total_price_cents' => $this->estimateWorkspaceTotalPrice($billingProfile),
            ];
        });

        $subscriptionState = $this->resolveSubscriptionState($subscription);
        $plan = $subscription?->plan;
        $accessState = $this->determineWorkspaceAccessState($tenant, $subscriptionState);

        return [
            'workspace_uuid' => $tenant->uuid,
            'workspace_status' => $tenant->status,
            'access_state' => $accessState['state'],
            'access_message' => $accessState['message'],
            'inventory_changes_allowed' => $accessState['inventory_changes_allowed'],
            'subscription' => $subscription ? [
                'uuid' => $subscription->uuid,
                'status' => $subscription->status,
                'effective_status' => $subscriptionState['status'],
                'status_message' => $subscriptionState['message'],
                'starts_at' => $this->formatDateTime($subscription->starts_at),
                'ends_at' => $this->formatDateTime($subscription->ends_at),
                'trial_ends_at' => $this->formatDateTime($subscription->trial_ends_at),
                'current_period_starts_at' => $this->formatDateTime($subscriptionState['period_starts_at']),
                'current_period_ends_at' => $this->formatDateTime($subscriptionState['period_ends_at']),
                'trial_expired_at' => $this->formatDateTime($subscriptionState['trial_expired_at']),
                'expires_at' => $this->formatDateTime($subscriptionState['expires_at']),
                'is_trial_active' => $subscriptionState['status'] === 'trialing',
                'is_trial_expired' => $subscriptionState['trial_expired_at'] !== null,
                'is_current_period_active' => $subscriptionState['is_current_period_active'],
                'plan' => $plan ? [
                    'uuid' => $plan->uuid,
                    'name' => $plan->name,
                    'billing_interval' => $plan->billing_interval,
                    'trial_days' => $plan->trial_days,
                    'features' => $plan->features ?? [],
                    'price_cents' => $plan->price_cents,
                    'price_per_property_cents' => $plan->price_per_property_cents,
                    'properties_included' => $plan->properties_included,
                ] : null,
                'billing_profile' => $billingProfile ? [
                    'uuid' => $billingProfile->uuid,
                    'name' => $billingProfile->name,
                    'billing_interval' => $billingProfile->billing_interval,
                    'trial_days' => $billingProfile->trial_days,
                    'grace_days' => $billingProfile->grace_days,
                    'currency' => $billingProfile->currency,
                    'status' => $billingProfile->status,
                ] : null,
            ] : null,
            'usage' => [
                'registered_properties' => $summaryUsage['total_properties'],
                'registered_units_total' => $summaryUsage['total_units'],
                'estimated_total_price_cents' => $summaryUsage['estimated_total_price_cents'],
                'property_breakdown_paginated' => true,
            ],
        ];
    }

    public function getWorkspaceSubscriptionPropertyBreakdown(Tenant $tenant, array $filters = []): LengthAwarePaginator
    {
        return $this->runInTenantContext($tenant, function () use ($tenant, $filters) {
            $subscription = $this->currentSubscription($tenant);
            $billingProfile = $subscription?->billingProfile ?? $this->resolveWorkspaceBillingProfile($tenant);
            $rules = $billingProfile
                ? $this->billingProfileService->activeRulesForDate($billingProfile)
                : collect();

            $query = $this->propertyUsageQuery();

            if (!empty($filters['name'] ?? null)) {
                $query->where('properties.name', 'like', $filters['name'].'%');
            }

            if (!empty($filters['status'] ?? null)) {
                $query->where('properties.status', $filters['status']);
            }

            $this->applyPropertyBreakdownSort($query, $filters['sort'] ?? null);

            $paginator = $query
                ->paginate((int) ($filters['per_page'] ?? 15))
                ->withQueryString();

            $paginator->getCollection()->transform(function (Property $property) use ($billingProfile, $rules) {
                $rule = $billingProfile
                    ? $this->billingProfileService->matchingRuleFromCollection($rules, (int) $property->registered_units)
                    : null;

                $property->setAttribute('matched_rule', $rule ? [
                    'uuid' => $rule->uuid,
                    'range_start' => $rule->range_start,
                    'range_end' => $rule->range_end,
                    'price_cents' => $rule->price_cents,
                    'currency' => $rule->currency,
                    'effective_from' => $rule->effective_from?->toDateString(),
                    'effective_to' => $rule->effective_to?->toDateString(),
                ] : null);
                $property->setAttribute('estimated_price_cents', $rule?->price_cents ?? 0);

                return $property;
            });

            return $paginator;
        });
    }

    public function currentSubscription(Tenant $tenant): ?Subscription
    {
        return Subscription::query()
            ->with(['plan', 'billingProfile'])
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();
    }

    public function updateSubscriptionStatus(Tenant $tenant, array|string $payload): ?Subscription
    {
        $attributes = is_array($payload) ? $payload : ['status' => $payload];
        $subscription = $this->currentSubscription($tenant);
        if (!$subscription) {
            return null;
        }

        $status = $attributes['status'];
        $effectiveAt = $this->resolveEffectiveAt($attributes['effective_at'] ?? null);
        $plan = $subscription->plan;

        if (!$plan) {
            throw new \RuntimeException('Subscription plan could not be resolved for this workspace.');
        }

        $subscription->status = $status;

        if ($status === 'trialing') {
            $subscription->starts_at = $effectiveAt;
            $subscription->trial_ends_at = $this->calculateTrialEndsAt($effectiveAt, (int) $plan->trial_days);
            $subscription->ends_at = $subscription->trial_ends_at;
        } elseif ($status === 'active') {
            $subscription->starts_at = $effectiveAt;
            $subscription->trial_ends_at = null;
            $subscription->ends_at = $this->calculateBillingEndsAt($effectiveAt, $plan->billing_interval);
        } elseif (in_array($status, ['past_due', 'canceled'], true) && $subscription->ends_at === null) {
            $subscription->ends_at = $effectiveAt->copy()->endOfDay();
        }

        $subscription->save();

        return $subscription->fresh(['plan', 'billingProfile']);
    }

    public function assertWorkspaceAllowsInventoryMutation(Tenant $tenant): void
    {
        $subscriptionState = $this->resolveSubscriptionState($this->currentSubscription($tenant));
        $accessState = $this->determineWorkspaceAccessState($tenant, $subscriptionState);

        if (!$accessState['inventory_changes_allowed']) {
            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => $accessState['message'],
                    'data' => null,
                    'errors' => [
                        'workspace' => [$accessState['message']],
                    ],
                ], 422)
            );
        }
    }

    private function resolveOnboardingPlan(?string $planUuid = null): Plan
    {
        $activePlans = Plan::query()->where('status', 'active');

        if (!empty($planUuid)) {
            $plan = (clone $activePlans)->where('uuid', $planUuid)->first();
            if ($plan) {
                return $plan;
            }
        }

        $starter = (clone $activePlans)
            ->whereIn('name', ['starter', 'free'])
            ->orderBy('price_cents')
            ->first();

        if ($starter) {
            return $starter;
        }

        $plan = (clone $activePlans)
            ->orderBy('price_cents')
            ->orderBy('properties_included')
            ->first();

        if ($plan) {
            return $plan;
        }

        throw new \RuntimeException('No active subscription plan is configured. Seed the landlord plans table before provisioning tenants.');
    }

    private function calculateWorkspaceInventoryTotals(): array
    {
        return [
            'total_properties' => Property::query()->count(),
            'total_units' => Unit::query()->count(),
        ];
    }

    private function estimateWorkspaceTotalPrice(?BillingProfile $billingProfile): int
    {
        if (!$billingProfile) {
            return 0;
        }

        $rules = $this->billingProfileService->activeRulesForDate($billingProfile);
        $frequencies = $this->propertyRegisteredUnitFrequencies();

        return (int) $frequencies->sum(function (object $frequency) use ($rules) {
            $rule = $this->billingProfileService->matchingRuleFromCollection($rules, (int) $frequency->registered_units);

            return ($rule?->price_cents ?? 0) * (int) $frequency->properties_count;
        });
    }

    private function propertyRegisteredUnitFrequencies(): Collection
    {
        $propertyUsage = Property::query()
            ->select([
                'properties.id',
                DB::raw('COUNT(units.id) as registered_units'),
            ])
            ->leftJoin('property_floors', 'property_floors.property_id', '=', 'properties.id')
            ->leftJoin('units', 'units.property_floor_id', '=', 'property_floors.id')
            ->groupBy('properties.id');

        return DB::query()
            ->fromSub($propertyUsage, 'property_usage')
            ->select([
                'registered_units',
                DB::raw('COUNT(*) as properties_count'),
            ])
            ->groupBy('registered_units')
            ->get();
    }

    private function propertyUsageQuery(): Builder
    {
        return Property::query()
            ->select([
                'properties.id',
                'properties.uuid',
                'properties.name',
                'properties.status',
                'properties.created_at',
                DB::raw('COUNT(units.id) as registered_units'),
            ])
            ->leftJoin('property_floors', 'property_floors.property_id', '=', 'properties.id')
            ->leftJoin('units', 'units.property_floor_id', '=', 'property_floors.id')
            ->groupBy('properties.id', 'properties.uuid', 'properties.name', 'properties.status', 'properties.created_at');
    }

    private function applyPropertyBreakdownSort(Builder $query, ?string $sort): void
    {
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $column = ltrim((string) $sort, '-');

        match ($column) {
            'registered_units' => $query->orderBy('registered_units', $direction)->orderBy('properties.name'),
            'created_at' => $query->orderBy('properties.created_at', $direction)->orderBy('properties.name'),
            'name', '' => $query->orderBy('properties.name', $direction),
            default => $query->orderBy('properties.name'),
        };
    }

    private function resolveWorkspaceBillingProfile(Tenant $tenant): ?BillingProfile
    {
        $billingProfileUuid = data_get($tenant->meta, 'billing_profile_uuid');

        if (!empty($billingProfileUuid)) {
            return BillingProfile::query()
                ->select(['id', 'uuid', 'name', 'billing_interval', 'trial_days', 'grace_days', 'currency', 'status'])
                ->where('uuid', $billingProfileUuid)
                ->first();
        }

        return BillingProfile::query()
            ->select(['id', 'uuid', 'name', 'billing_interval', 'trial_days', 'grace_days', 'currency', 'status'])
            ->where('status', 'active')
            ->where('is_default', true)
            ->first();
    }

    private function updateUsageMetric(int $tenantId, string $metric, int $quantity, Carbon $now, array $meta = []): void
    {
        SubscriptionUsage::query()->updateOrCreate([
            'tenant_id' => $tenantId,
            'metric' => $metric,
            'period_start' => $now->copy()->startOfMonth()->toDateString(),
        ], [
            'period_end' => $now->copy()->endOfMonth()->toDateString(),
            'quantity' => $quantity,
            'billed' => false,
            'meta' => $meta,
        ]);
    }

    private function determineWorkspaceAccessState(Tenant $tenant, array $subscriptionState): array
    {
        if ($tenant->status === 'suspended') {
            return [
                'state' => 'suspended',
                'message' => 'This workspace is currently suspended.',
                'inventory_changes_allowed' => false,
            ];
        }

        if ($tenant->provisioning_status !== 'ready') {
            return [
                'state' => 'provisioning',
                'message' => 'This workspace is still being prepared.',
                'inventory_changes_allowed' => false,
            ];
        }

        if ($subscriptionState['status'] === 'unconfigured') {
            return [
                'state' => 'unconfigured',
                'message' => 'Workspace billing is not configured yet.',
                'inventory_changes_allowed' => false,
            ];
        }

        return match ($subscriptionState['status']) {
            'active', 'trialing' => [
                'state' => $subscriptionState['status'],
                'message' => $subscriptionState['message'],
                'inventory_changes_allowed' => true,
            ],
            'past_due' => [
                'state' => 'past_due',
                'message' => $subscriptionState['message'],
                'inventory_changes_allowed' => false,
            ],
            'canceled' => [
                'state' => 'canceled',
                'message' => 'Workspace subscription is canceled. Inventory changes are not allowed.',
                'inventory_changes_allowed' => false,
            ],
            default => [
                'state' => 'restricted',
                'message' => 'Workspace access is restricted at the moment.',
                'inventory_changes_allowed' => false,
            ],
        };
    }

    private function resolveSubscriptionState(?Subscription $subscription): array
    {
        if (!$subscription) {
            return [
                'status' => 'unconfigured',
                'message' => 'Workspace billing is not configured yet.',
                'period_starts_at' => null,
                'period_ends_at' => null,
                'trial_expired_at' => null,
                'expires_at' => null,
                'is_current_period_active' => false,
            ];
        }

        $now = now();
        $effectiveStatus = $subscription->status;
        $message = $subscription->status === 'trialing'
            ? 'Workspace access is active during the trial period.'
            : 'Workspace access is active.';
        $trialExpiredAt = null;
        $expiresAt = null;
        $isCurrentPeriodActive = true;

        if ($subscription->status === 'trialing' && $subscription->trial_ends_at?->lt($now)) {
            $effectiveStatus = 'past_due';
            $trialExpiredAt = $subscription->trial_ends_at;
            $expiresAt = $subscription->trial_ends_at;
            $message = 'The trial period ended on '.$subscription->trial_ends_at->format('Y-m-d H:i:s').'. Payment is required to continue.';
            $isCurrentPeriodActive = false;
        } elseif ($subscription->status === 'active' && $subscription->ends_at?->lt($now)) {
            $effectiveStatus = 'past_due';
            $expiresAt = $subscription->ends_at;
            $message = 'The current billing period ended on '.$subscription->ends_at->format('Y-m-d H:i:s').'. Payment is required to continue.';
            $isCurrentPeriodActive = false;
        } elseif ($subscription->status === 'past_due') {
            $message = 'Workspace billing is overdue. Payment is required before inventory changes can continue.';
            $expiresAt = $subscription->ends_at ?? $subscription->trial_ends_at;
            $isCurrentPeriodActive = false;
        } elseif ($subscription->status === 'canceled') {
            $message = 'Workspace subscription is canceled.';
            $expiresAt = $subscription->ends_at ?? $subscription->trial_ends_at;
            $isCurrentPeriodActive = false;
        }

        return [
            'status' => $effectiveStatus,
            'message' => $message,
            'period_starts_at' => $subscription->starts_at,
            'period_ends_at' => $subscription->status === 'trialing'
                ? $subscription->trial_ends_at
                : $subscription->ends_at,
            'trial_expired_at' => $trialExpiredAt,
            'expires_at' => $expiresAt,
            'is_current_period_active' => $isCurrentPeriodActive,
        ];
    }

    private function calculateTrialEndsAt(CarbonInterface|string $startsAt, int $trialDays): Carbon
    {
        $startsAt = $this->normalizeDateAnchor($startsAt);
        $inclusiveDays = max($trialDays, 1) - 1;

        return $startsAt->copy()->addDays($inclusiveDays)->endOfDay();
    }

    private function calculateBillingEndsAt(CarbonInterface|string $startsAt, string $billingInterval): Carbon
    {
        $startsAt = $this->normalizeDateAnchor($startsAt);

        return match ($billingInterval) {
            'quarterly' => $startsAt->copy()->addMonthsNoOverflow(3)->subDay()->endOfDay(),
            'annual', 'annually' => $startsAt->copy()->addYearNoOverflow()->subDay()->endOfDay(),
            default => $startsAt->copy()->addMonthNoOverflow()->subDay()->endOfDay(),
        };
    }

    private function resolveEffectiveAt(?string $value = null): Carbon
    {
        return $value !== null
            ? Carbon::parse($value)->startOfDay()
            : now()->startOfDay();
    }

    private function normalizeDateAnchor(CarbonInterface|string $value): Carbon
    {
        return $value instanceof CarbonInterface
            ? Carbon::parse($value->format('Y-m-d H:i:s'))->startOfDay()
            : Carbon::parse($value)->startOfDay();
    }

    private function formatDateTime(?CarbonInterface $value): ?string
    {
        return $value?->format('Y-m-d H:i:s');
    }

    private function runInTenantContext(Tenant $tenant, callable $callback): mixed
    {
        $currentTenant = Tenant::current();

        if ($currentTenant?->id === $tenant->id) {
            return $callback();
        }

        $tenant->makeCurrent();

        try {
            return $callback();
        } finally {
            Tenant::forgetCurrent();

            if ($currentTenant && $currentTenant->id !== $tenant->id) {
                $currentTenant->makeCurrent();
            }
        }
    }
}
