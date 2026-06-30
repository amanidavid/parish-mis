<?php

namespace App\Services\V1;

use App\Models\Landlord\BillingProfile;
use App\Models\Landlord\BillingRule;
use App\Models\Landlord\PropertySubscription;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Subscription;
use App\Models\Landlord\SubscriptionUsage;
use App\Models\Landlord\WorkspaceProperty;
use App\Models\Tenant\Property;
use App\Models\Tenant\Unit;
use App\Models\Tenancy\Tenant;
use App\Services\V1\Billing\WorkspaceBillingRuleService;
use App\Support\Tenancy\TenantConnectionManager;
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
    private const BILLING_PROFILE_COLUMNS = ['id', 'uuid', 'name', 'billing_interval', 'trial_days', 'grace_days', 'currency', 'status'];
    /** Tracks property count usage for workspace-level subscription metering. */
    private const METRIC_PROPERTIES = 'properties';
    /** Tracks total registered units because billing profile rules are unit-band based. */
    private const METRIC_REGISTERED_UNITS_TOTAL = 'registered_units_total';
    /** Only these subscription states are considered open for provisioning-time reuse. */
    private const OPEN_SUBSCRIPTION_STATUSES = ['trialing', 'active'];

    /**
     * Create a new instance.
     */
    public function __construct(
        private BillingProfileService $billingProfileService,
        private WorkspaceBillingRuleService $workspaceBillingRuleService,
        private TenantConnectionManager $tenantConnectionManager
    )
    {
    }

    /** Create the first workspace subscription during provisioning and seed the starting usage rows. */
    /**
     * Create trial subscription for tenant.
     */
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

    /** Refresh the current workspace usage counters that billing summaries and guards depend on. */
    /**
     * Sync workspace usage.
     */
    public function syncWorkspaceUsage(Tenant $tenant): array
    {
        $billingRule = $this->workspaceBillingRuleService->activeRule();
        $counts = $this->runInTenantContext($tenant, fn () => $this->calculateUsageSummary($billingRule));
        $now = now();

        $this->updateUsageMetric($tenant->id, self::METRIC_PROPERTIES, $counts['total_properties'], $now, [
            'pricing_model' => 'property_count',
        ]);

        $this->updateUsageMetric($tenant->id, self::METRIC_REGISTERED_UNITS_TOTAL, $counts['total_units'], $now, [
            'pricing_model' => 'registered_units_total',
        ]);

        return $counts;
    }

    /** Build the tenant/admin subscription summary, including any pending billing profile change. */
    /**
     * Get workspace subscription summary.
     */
    public function getWorkspaceSubscriptionSummary(Tenant $tenant): array
    {
        $subscription = $this->currentSubscription($tenant);
        $billingRule = $this->workspaceBillingRuleService->activeRule();
        $summaryUsage = $this->runInTenantContext($tenant, function () use ($billingRule) {
            return $this->calculateUsageSummary($billingRule);
        });

        $subscriptionState = $this->resolveSubscriptionState($subscription);
        $plan = $subscription?->plan;
        $accessState = $this->determineWorkspaceAccessState($tenant, $subscriptionState);

        return [
            'workspace_uuid' => $tenant->uuid,
            'name' => $tenant->name,
            'display_name' => $tenant->display_name,
            'database' => $tenant->database,
            'workspace_status' => $tenant->status,
            'created_at' => $this->formatDateTime($tenant->created_at),
            'updated_at' => $this->formatDateTime($tenant->updated_at),
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
                'billing_rule' => $this->workspaceBillingRuleService->formatRule(
                    $billingRule
                ),
            ] : null,
            'usage' => [
                'registered_properties' => $summaryUsage['total_properties'],
                'registered_units_total' => $summaryUsage['total_units'],
                'estimated_total_price_cents' => $summaryUsage['estimated_total_price_cents'],
                'workspace_unit_price_cents' => (int) ($billingRule?->unit_price_cents ?? 0),
                'property_breakdown_paginated' => true,
            ],
        ];
    }

    /** Return the paginated per-property billing estimate breakdown for drill-down screens. */
    /**
     * Get workspace subscription property breakdown.
     */
    public function getWorkspaceSubscriptionPropertyBreakdown(Tenant $tenant, array $filters = []): LengthAwarePaginator
    {
        return $this->runInTenantContext($tenant, function () use ($tenant, $filters) {
            $billingRule = $this->workspaceBillingRuleService->activeRule();

            $query = $this->propertyUsageQuery();

            if (!empty($filters['search'] ?? null)) {
                $query->where('properties.name', 'like', $filters['search'].'%');
            }

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

            $propertySubscriptions = $this->workspacePropertySubscriptionMap(
                $tenant->id,
                $paginator->getCollection()->pluck('uuid')
            );

            $paginator->getCollection()->transform(function (Property $property) use ($billingRule, $propertySubscriptions) {
                $this->decoratePropertyUsageRow($property, $billingRule, $propertySubscriptions->get($property->uuid));

                return $property;
            });

            return $paginator;
        });
    }

    /** Fetch the latest subscription record that represents the workspace's current billing state. */
    /**
     * Handle current subscription.
     */
    public function currentSubscription(Tenant $tenant): ?Subscription
    {
        return Subscription::query()
            ->select([
                'id',
                'uuid',
                'tenant_id',
                'plan_id',
                'billing_profile_id',
                'status',
                'starts_at',
                'ends_at',
                'trial_ends_at',
                'created_at',
                'updated_at',
            ])
            ->with(['plan', 'billingProfile'])
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();
    }

    /** Change subscription lifecycle status and recalculate period anchors for that status. */
    /**
     * Update subscription status.
     */
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

    /** Block inventory mutations when the workspace billing state does not allow operational changes. */
    /**
     * Assert workspace allows inventory mutation.
     */
    public function assertWorkspaceAllowsInventoryMutation(Tenant $tenant, array $messageOverrides = []): void
    {
        $accessState = $this->workspaceAccessState($tenant);

        if (!$accessState['inventory_changes_allowed']) {
            $message = $messageOverrides[$accessState['state'] ?? ''] ?? $accessState['message'];

            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => $message,
                    'data' => null,
                    'errors' => [
                        'workspace' => [$message],
                    ],
                ], 422)
            );
        }
    }

    /**
     * Resolve workspace access state.
     */
    public function workspaceAccessState(Tenant $tenant): array
    {
        $subscriptionState = $this->resolveSubscriptionState($this->currentSubscription($tenant));

        return $this->determineWorkspaceAccessState($tenant, $subscriptionState);
    }

    /**
     * Assert workspace allows property-scoped operational mutation.
     */
    public function assertWorkspaceAllowsPropertyScopedMutation(Tenant $tenant): void
    {
        $subscriptionState = $this->resolveSubscriptionState($this->currentSubscription($tenant));

        if ($tenant->status === 'suspended') {
            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => 'This workspace is suspended. Contact the platform administrator to restore access.',
                    'data' => null,
                    'errors' => [
                        'workspace' => ['This workspace is suspended. Contact the platform administrator to restore access.'],
                    ],
                ], 422)
            );
        }

        if ($tenant->provisioning_status !== 'ready') {
            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => 'This workspace setup is not complete yet. Please try again shortly.',
                    'data' => null,
                    'errors' => [
                        'workspace' => ['This workspace setup is not complete yet. Please try again shortly.'],
                    ],
                ], 422)
            );
        }

        if (($subscriptionState['status'] ?? null) === 'unconfigured') {
            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => 'Workspace billing has not been configured yet. Please contact the platform administrator.',
                    'data' => null,
                    'errors' => [
                        'workspace' => ['Workspace billing has not been configured yet. Please contact the platform administrator.'],
                    ],
                ], 422)
            );
        }
    }

    /**
     * Resolve onboarding plan.
     */
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

    /**
     * Calculate usage summary.
     */
    private function calculateUsageSummary(mixed $pricingSource): array
    {
        $frequencies = $this->propertyRegisteredUnitFrequencies();

        $totalProperties = (int) $frequencies->sum('properties_count');
        $totalUnits = (int) $frequencies->sum(
            fn (object $row) => (int) $row->registered_units * (int) $row->properties_count
        );
        $estimatedPrice = $this->estimateTotalPriceFromFrequencies($frequencies, $pricingSource);

        return [
            'total_properties'           => $totalProperties,
            'total_units'                => $totalUnits,
            'estimated_total_price_cents' => $estimatedPrice,
        ];
    }

    /** @deprecated Use calculateUsageSummary() which runs a single query instead of two. */
    /**
     * Calculate workspace inventory totals.
     */
    private function calculateWorkspaceInventoryTotals(): array
    {
        return [
            'total_properties' => Property::query()->count(),
            'total_units'      => Unit::query()->count(),
        ];
    }

    /** Reuse grouped property usage to estimate the total billing amount without scanning each property repeatedly. */
    /**
     * Handle estimate total price from frequencies.
     */
    public function estimateTotalPriceFromFrequencies(Collection $frequencies, mixed $pricingSource, CarbonInterface|string|null $date = null): int
    {
        if (!$pricingSource) {
            return 0;
        }

        if ($pricingSource instanceof BillingRule) {
            return (int) $frequencies->sum(function (object $frequency) use ($pricingSource) {
                return ((int) $frequency->registered_units * (int) $pricingSource->unit_price_cents) * (int) $frequency->properties_count;
            });
        }

        if (!$pricingSource instanceof BillingProfile) {
            return 0;
        }

        $rules = $this->billingProfileService->activeRulesForDate($pricingSource, $date);

        return (int) $frequencies->sum(function (object $frequency) use ($rules) {
            $rule = $this->billingProfileService->matchingRuleFromCollection($rules, (int) $frequency->registered_units);

            return ($rule?->unit_price_cents ?? 0) * (int) $frequency->properties_count;
        });
    }

    /** Aggregate workspace properties by registered-unit bucket so pricing can be calculated efficiently. */
    /**
     * Get workspace property usage frequencies.
     */
    public function getWorkspacePropertyUsageFrequencies(Tenant $tenant): Collection
    {
        return $this->runInTenantContext($tenant, fn () => $this->propertyRegisteredUnitFrequencies());
    }

    /**
     * Get workspace property pricing snapshot.
     */
    public function getWorkspacePropertyPricingSnapshot(
        Tenant $tenant,
        ?BillingRule $billingRule = null,
        CarbonInterface|string|null $date = null
    ): Collection {
        return $this->runInTenantContext($tenant, function () use ($tenant, $billingRule, $date) {
            $activeRule = $billingRule ?? $this->workspaceBillingRuleService->activeRule($date);

            return $this->propertyUsageQuery()
                ->orderBy('properties.name')
                ->get()
                ->map(function (Property $property) use ($activeRule) {
                    $this->decoratePropertyUsageRow($property, $activeRule);

                    return [
                        'property_uuid' => $property->uuid,
                        'name' => $property->name,
                        'status' => $property->status,
                        'registered_units' => (int) $property->registered_units,
                        'estimated_price_cents' => (int) $property->estimated_price_cents,
                        'workspace_billing_rule' => $property->workspace_billing_rule,
                    ];
                })
                ->values();
        });
    }

    /** Resolve the billing profile currently governing the workspace, falling back to the active default profile. */
    /**
     * Resolve workspace billing profile.
     */
    public function resolveWorkspaceBillingProfile(Tenant $tenant): ?BillingProfile
    {
        $billingProfileUuid = data_get($tenant->meta, 'billing_profile_uuid');

        if (!empty($billingProfileUuid)) {
            return BillingProfile::query()
                ->select(self::BILLING_PROFILE_COLUMNS)
                ->where('uuid', $billingProfileUuid)
                ->first();
        }

        return BillingProfile::query()
            ->select(self::BILLING_PROFILE_COLUMNS)
            ->where('status', 'active')
            ->where('is_default', true)
            ->first();
    }

    /** Convert the raw subscription row into an effective state the rest of the app can reason about. */
    /**
     * Resolve subscription state.
     */
    public function resolveSubscriptionState(?Subscription $subscription): array
    {
        if (!$subscription) {
            return [
                'status' => 'unconfigured',
                'message' => 'Workspace billing has not been configured yet. Please contact the platform administrator.',
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
            $message = 'Your workspace trial ended on '.$subscription->trial_ends_at->format('Y-m-d H:i:s').'. A paid subscription is required to continue.';
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

    /**
     * Property registered unit frequencies.
     */
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

        return $this->tenantQuery()
            ->fromSub($propertyUsage, 'property_usage')
            ->select([
                'registered_units',
                DB::raw('COUNT(*) as properties_count'),
            ])
            ->groupBy('registered_units')
            ->get();
    }

    /**
     * Property usage query.
     */
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

    /**
     * Resolve the billing profile attached to the current subscription, falling back to workspace default configuration.
     */
    private function resolveSubscriptionBillingProfile(Tenant $tenant, ?Subscription $subscription): ?BillingProfile
    {
        return $subscription?->billingProfile ?? $this->resolveWorkspaceBillingProfile($tenant);
    }

    /**
     * Load property subscription records for the current paginator page with only the fields needed for status rendering.
     */
    private function workspacePropertySubscriptionMap(int $tenantId, Collection $propertyUuids): Collection
    {
        $uuids = $propertyUuids
            ->filter(fn ($uuid) => filled($uuid))
            ->values()
            ->all();

        if ($uuids === []) {
            return collect();
        }

        return WorkspaceProperty::query()
            ->select(['id', 'tenant_id', 'property_uuid'])
            ->with([
                'subscription:id,workspace_property_id,status,current_period_starts_on,current_period_ends_on,expired_on',
            ])
            ->where('tenant_id', $tenantId)
            ->whereIn('property_uuid', $uuids)
            ->get()
            ->keyBy('property_uuid');
    }

    /**
     * Attach computed pricing and subscription attributes so list and snapshot responses stay consistent.
     */
    private function decoratePropertyUsageRow(
        Property $property,
        ?BillingRule $billingRule,
        ?WorkspaceProperty $workspaceProperty = null
    ): void {
        $propertySubscription = $workspaceProperty?->subscription;

        $property->setAttribute('workspace_billing_rule', $this->workspaceBillingRuleService->formatRule($billingRule));
        $property->setAttribute(
            'estimated_price_cents',
            $this->workspaceBillingRuleService->calculateMonthlyCharge((int) $property->registered_units, $billingRule)
        );
        $property->setAttribute(
            'subscription_status',
            $propertySubscription?->effectiveStatus() ?? PropertySubscription::STATUS_UNSUBSCRIBED
        );
    }

    /**
     * Apply property breakdown sort.
     */
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

    /**
     * Update usage metric.
     */
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

    /**
     * Determine workspace access state.
     */
    private function determineWorkspaceAccessState(Tenant $tenant, array $subscriptionState): array
    {
        if ($tenant->status === 'suspended') {
            return [
                'state' => 'suspended',
                'message' => 'This workspace is suspended. Contact the platform administrator to restore access.',
                'inventory_changes_allowed' => false,
            ];
        }

        if ($tenant->provisioning_status !== 'ready') {
            return [
                'state' => 'provisioning',
                'message' => 'This workspace setup is not complete yet. Please try again shortly.',
                'inventory_changes_allowed' => false,
            ];
        }

        if ($subscriptionState['status'] === 'unconfigured') {
            return [
                'state' => 'unconfigured',
                'message' => 'Workspace billing has not been configured yet. Please contact the platform administrator.',
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

    /**
     * Calculate trial ends at.
     */
    private function calculateTrialEndsAt(CarbonInterface|string $startsAt, int $trialDays): Carbon
    {
        $startsAt = $this->normalizeDateAnchor($startsAt);
        $inclusiveDays = max($trialDays, 1) - 1;

        return $startsAt->copy()->addDays($inclusiveDays)->endOfDay();
    }

    /**
     * Calculate billing ends at.
     */
    private function calculateBillingEndsAt(CarbonInterface|string $startsAt, string $billingInterval): Carbon
    {
        $startsAt = $this->normalizeDateAnchor($startsAt);

        return match ($billingInterval) {
            'quarterly' => $startsAt->copy()->addMonthsNoOverflow(3)->subDay()->endOfDay(),
            'annual', 'annually' => $startsAt->copy()->addYearNoOverflow()->subDay()->endOfDay(),
            default => $startsAt->copy()->addMonthNoOverflow()->subDay()->endOfDay(),
        };
    }

    /**
     * Resolve effective at.
     */
    private function resolveEffectiveAt(?string $value = null): Carbon
    {
        return $value !== null
            ? Carbon::parse($value)->startOfDay()
            : now()->startOfDay();
    }

    /**
     * Normalize date anchor.
     */
    private function normalizeDateAnchor(CarbonInterface|string $value): Carbon
    {
        return $value instanceof CarbonInterface
            ? Carbon::parse($value->format('Y-m-d H:i:s'))->startOfDay()
            : Carbon::parse($value)->startOfDay();
    }

    /**
     * Format date time.
     */
    private function formatDateTime(?CarbonInterface $value): ?string
    {
        return $value?->format('Y-m-d H:i:s');
    }

    /**
     * Run in tenant context.
     */
    private function runInTenantContext(Tenant $tenant, callable $callback): mixed
    {
        $currentTenant = Tenant::current();

        if ($currentTenant?->id === $tenant->id) {
            $this->tenantConnectionManager->activateTenant($tenant);

            return $callback();
        }

        $this->tenantConnectionManager->activateTenant($tenant);

        try {
            return $callback();
        } finally {
            $this->tenantConnectionManager->restoreTenant(
                $currentTenant && $currentTenant->id !== $tenant->id ? $currentTenant : null
            );
        }
    }

    /**
     * Tenant query.
     */
    private function tenantQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::connection($this->tenantConnectionName())->query();
    }

    /**
     * Tenant connection name.
     */
    private function tenantConnectionName(): string
    {
        return $this->tenantConnectionManager->connectionName();
    }
}
