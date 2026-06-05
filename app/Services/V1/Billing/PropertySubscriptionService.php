<?php

namespace App\Services\V1\Billing;

use App\Models\Landlord\BillingRule;
use App\Models\Landlord\PropertySubscription;
use App\Models\Landlord\PropertySubscriptionPayment;
use App\Models\Landlord\WorkspaceProperty;
use App\Models\Tenancy\Tenant;
use App\Services\V1\SubscriptionService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PropertySubscriptionService
{
    private const WORKSPACE_REPORT_TOTALS_CACHE_TTL_SECONDS = 60;

    public function __construct(
        private WorkspacePropertyRegistryService $workspacePropertyRegistryService,
        private PropertySubscriptionAccessService $propertySubscriptionAccessService,
        private SubscriptionService $subscriptionService,
    ) {
    }

    public function listTenantPropertySubscriptions(Tenant $tenant, array $filters = []): LengthAwarePaginator
    {
        $this->workspacePropertyRegistryService->ensureTenantSynced($tenant);

        $query = WorkspaceProperty::query()
            ->with([
                'subscription.billingRule.profile:id,uuid,name,billing_interval,currency,status',
                'subscription.latestPayment',
                'latestPayment',
            ])
            ->withCount('payments')
            ->withSum('payments as total_paid_amount_cents', 'total_amount_cents')
            ->leftJoin('property_subscriptions as property_subscription_sort', 'property_subscription_sort.workspace_property_id', '=', 'workspace_properties.id')
            ->select('workspace_properties.*')
            ->where('workspace_properties.tenant_id', $tenant->id);

        if (!(bool) ($filters['include_deleted'] ?? false)) {
            $query->whereNull('workspace_properties.property_deleted_at');
        }

        if (!empty($filters['search'] ?? null)) {
            $this->applyPrefixSearch($query, 'workspace_properties.property_name', (string) $filters['search']);
        }

        if (!empty($filters['property_status'] ?? null)) {
            $query->where('workspace_properties.property_status', $filters['property_status']);
        }

        if (!empty($filters['subscription_status'] ?? null)) {
            $this->applySubscriptionStatusFilter($query, $filters['subscription_status'], 'property_subscription_sort');
        }

        $this->applyTenantPropertySubscriptionSort($query, $filters['sort'] ?? null);

        return $query
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();
    }

    public function getTenantPropertySubscription(Tenant $tenant, string $propertyUuid): ?WorkspaceProperty
    {
        $workspaceProperty = $this->workspacePropertyRegistryService->resolveWorkspaceProperty($tenant, $propertyUuid);

        if (!$workspaceProperty || $workspaceProperty->tenant_id !== $tenant->id) {
            return null;
        }

        $workspaceProperty->load([
            'payments' => fn ($builder) => $builder
                ->with($this->paymentRelations())
                ->orderByDesc('payment_date')
                ->orderByDesc('created_at')
                ->limit(10),
        ]);
        $workspaceProperty->setAttribute('payments_count', $workspaceProperty->payments()->count());
        $workspaceProperty->setAttribute('total_paid_amount_cents', (int) $workspaceProperty->payments()->sum('total_amount_cents'));
        $workspaceProperty->setRelation('activePayment', $this->resolveActivePayment($workspaceProperty));

        return $workspaceProperty;
    }

    public function previewPayment(Tenant $tenant, array $payload): array
    {
        $workspaceProperty = $this->resolvePayableWorkspaceProperty($tenant, (string) $payload['property_uuid']);
        $billingRule = $this->resolveBillableRule((string) $payload['billing_rule_uuid'], (string) $payload['payment_date']);
        $monthsPaid = (int) $payload['months_paid'];
        $paymentDate = Carbon::parse($payload['payment_date'])->startOfDay();
        $subscription = $workspaceProperty->subscription;
        $coverage = $this->resolveCoverage($tenant, $subscription, $paymentDate, $monthsPaid);
        $monthlyPrice = (int) $billingRule->price_cents;
        $workspaceTrialEndsOn = $this->propertySubscriptionAccessService->activeWorkspaceTrialEndsOn($tenant, $paymentDate);

        return [
            'workspace_uuid' => $tenant->uuid,
            'property' => [
                'uuid' => $workspaceProperty->property_uuid,
                'name' => $workspaceProperty->property_name,
                'status' => $workspaceProperty->property_status,
                'current_registered_units_total' => (int) $workspaceProperty->current_registered_units_total,
                'is_deleted' => $workspaceProperty->property_deleted_at !== null,
            ],
            'subscription_before' => $this->formatSubscription($subscription),
            'billing_rule' => [
                'uuid' => $billingRule->uuid,
                'range_start' => (int) $billingRule->range_start,
                'range_end' => $billingRule->range_end !== null ? (int) $billingRule->range_end : null,
                'price_cents' => $monthlyPrice,
                'currency' => $billingRule->profile?->currency ?? 'TZS',
                'billing_profile' => $billingRule->profile ? [
                    'uuid' => $billingRule->profile->uuid,
                    'name' => $billingRule->profile->name,
                    'billing_interval' => $billingRule->profile->billing_interval,
                ] : null,
            ],
            'payment' => [
                'months_paid' => $monthsPaid,
                'payment_date' => $paymentDate->toDateString(),
                'monthly_price_cents' => $monthlyPrice,
                'total_amount_cents' => $monthlyPrice * $monthsPaid,
                'reference_number' => $payload['reference_number'] ?? null,
                'notes' => $payload['notes'] ?? null,
            ],
            'coverage' => [
                'starts_on' => $coverage['starts_on']->toDateString(),
                'ends_on' => $coverage['ends_on']->toDateString(),
                'starts_from_payment_date' => $coverage['starts_from_payment_date'],
            ],
            'workspace_trial' => [
                'active' => $workspaceTrialEndsOn !== null,
                'ends_on' => $workspaceTrialEndsOn?->toDateString(),
            ],
            'subscription_after' => [
                'status' => PropertySubscription::STATUS_ACTIVE,
                'effective_status' => PropertySubscription::STATUS_ACTIVE,
                'current_period_starts_on' => $coverage['starts_on']->toDateString(),
                'current_period_ends_on' => $coverage['ends_on']->toDateString(),
                'last_paid_on' => $paymentDate->toDateString(),
            ],
        ];
    }

    public function recordPayment(Tenant $tenant, array $payload, ?object $adminUser = null): PropertySubscriptionPayment
    {
        $workspaceProperty = $this->resolvePayableWorkspaceProperty($tenant, (string) $payload['property_uuid']);
        $billingRule = $this->resolveBillableRule((string) $payload['billing_rule_uuid'], (string) $payload['payment_date']);
        $paymentDate = Carbon::parse($payload['payment_date'])->startOfDay();
        $monthsPaid = (int) $payload['months_paid'];

        return DB::connection('base')->transaction(function () use ($tenant, $workspaceProperty, $billingRule, $paymentDate, $monthsPaid, $payload, $adminUser) {
            $lockedProperty = WorkspaceProperty::query()
                ->whereKey($workspaceProperty->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedProperty->property_deleted_at) {
                throw new InvalidArgumentException('Payments cannot be recorded for a deleted property.');
            }

            /** @var PropertySubscription $subscription */
            $subscription = PropertySubscription::query()
                ->where('workspace_property_id', $lockedProperty->id)
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                $subscription = PropertySubscription::query()->create([
                    'tenant_id' => $tenant->id,
                    'workspace_property_id' => $lockedProperty->id,
                    'status' => PropertySubscription::STATUS_UNSUBSCRIBED,
                ]);
            }

            $coverage = $this->resolveCoverage($tenant, $subscription, $paymentDate, $monthsPaid);
            $payment = PropertySubscriptionPayment::query()->create([
                'tenant_id' => $tenant->id,
                'workspace_property_id' => $lockedProperty->id,
                'property_subscription_id' => $subscription->id,
                'billing_rule_id' => $billingRule->id,
                'recorded_by_user_id' => $adminUser?->id,
                'months_paid' => $monthsPaid,
                'rule_range_start' => (int) $billingRule->range_start,
                'rule_range_end' => $billingRule->range_end !== null ? (int) $billingRule->range_end : null,
                'monthly_price_cents' => (int) $billingRule->price_cents,
                'total_amount_cents' => (int) $billingRule->price_cents * $monthsPaid,
                'currency' => $billingRule->profile?->currency ?? 'TZS',
                'payment_date' => $paymentDate->toDateString(),
                'coverage_starts_on' => $coverage['starts_on']->toDateString(),
                'coverage_ends_on' => $coverage['ends_on']->toDateString(),
                'reference_number' => $payload['reference_number'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'meta' => [
                    'recorded_by_name' => $adminUser?->name ?? null,
                    'billing_profile_uuid' => $billingRule->profile?->uuid,
                    'billing_profile_name' => $billingRule->profile?->name,
                ],
            ]);

            $subscription->fill([
                'billing_rule_id' => $billingRule->id,
                'status' => PropertySubscription::STATUS_ACTIVE,
                'current_period_starts_on' => $coverage['starts_on']->toDateString(),
                'current_period_ends_on' => $coverage['ends_on']->toDateString(),
                'last_paid_on' => $paymentDate->toDateString(),
                'activated_on' => $subscription->activated_on ?: $coverage['starts_on']->toDateString(),
                'expired_on' => null,
            ])->save();

            return $payment->load([
                'workspaceProperty.subscription.billingRule.profile:id,uuid,name,billing_interval,currency,status',
                'propertySubscription.billingRule.profile:id,uuid,name,billing_interval,currency,status',
                'billingRule.profile:id,uuid,name,billing_interval,currency,status',
            ]);
        });
    }

    public function listPayments(Tenant $tenant, array $filters = []): LengthAwarePaginator
    {
        $query = PropertySubscriptionPayment::query()
            ->with($this->paymentRelations())
            ->where('tenant_id', $tenant->id);

        if (!empty($filters['property_uuid'] ?? null)) {
            $propertyUuid = (string) $filters['property_uuid'];
            $query->whereHas('workspaceProperty', fn (EloquentBuilder $builder) => $builder->where('property_uuid', $propertyUuid));
        }

        if (!empty($filters['billing_rule_uuid'] ?? null)) {
            $billingRuleUuid = (string) $filters['billing_rule_uuid'];
            $query->whereHas('billingRule', fn (EloquentBuilder $builder) => $builder->where('uuid', $billingRuleUuid));
        }

        if (!empty($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);
            $query->whereHas('workspaceProperty', fn (EloquentBuilder $builder) => $this->applyPrefixSearch($builder, 'property_name', $search));
        }

        if (!empty($filters['start_date'] ?? null)) {
            $query->where('payment_date', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'] ?? null)) {
            $query->where('payment_date', '<=', $filters['end_date']);
        }

        $this->applyPaymentSort($query, $filters['sort'] ?? null);

        return $query
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();
    }

    public function paymentCollectionSummary(array $filters = []): array
    {
        $query = PropertySubscriptionPayment::query();

        if (!empty($filters['start_date'] ?? null)) {
            $query->where('payment_date', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'] ?? null)) {
            $query->where('payment_date', '<=', $filters['end_date']);
        }

        if (!empty($filters['tenant_uuid'] ?? null)) {
            $tenantUuid = (string) $filters['tenant_uuid'];
            $tenantId = Tenant::query()->where('uuid', $tenantUuid)->value('id');

            if (!$tenantId) {
                return [
                    'filters' => [
                        'tenant_uuid' => $filters['tenant_uuid'] ?? null,
                        'start_date' => $filters['start_date'] ?? null,
                        'end_date' => $filters['end_date'] ?? null,
                    ],
                    'totals' => [
                        'payments_count' => 0,
                        'total_collected_amount_cents' => 0,
                    ],
                ];
            }

            $query->where('tenant_id', $tenantId);
        }

        $totals = $query
            ->selectRaw('COUNT(id) as payments_count')
            ->selectRaw('COALESCE(SUM(total_amount_cents), 0) as total_collected_amount_cents')
            ->first();

        return [
            'filters' => [
                'tenant_uuid' => $filters['tenant_uuid'] ?? null,
                'start_date' => $filters['start_date'] ?? null,
                'end_date' => $filters['end_date'] ?? null,
            ],
            'totals' => [
                'payments_count' => (int) ($totals->payments_count ?? 0),
                'total_collected_amount_cents' => (int) ($totals->total_collected_amount_cents ?? 0),
            ],
        ];
    }

    public function workspaceReport(array $filters = []): array
    {
        $paymentSummary = DB::connection('base')->table('property_subscription_payments')
            ->select('tenant_id')
            ->selectRaw('COUNT(id) as payments_count')
            ->selectRaw('COALESCE(SUM(total_amount_cents), 0) as total_collected_amount_cents');

        if (!empty($filters['start_date'] ?? null)) {
            $paymentSummary->where('payment_date', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'] ?? null)) {
            $paymentSummary->where('payment_date', '<=', $filters['end_date']);
        }

        $paymentSummary->groupBy('tenant_id');

        $statusExpression = $this->effectiveStatusExpression('property_subscriptions');
        $propertySummary = DB::connection('base')->table('workspace_properties')
            ->leftJoin('property_subscriptions', 'property_subscriptions.workspace_property_id', '=', 'workspace_properties.id')
            ->whereNull('workspace_properties.property_deleted_at')
            ->select('workspace_properties.tenant_id')
            ->selectRaw('COUNT(workspace_properties.id) as total_properties')
            ->selectRaw("SUM(CASE WHEN {$statusExpression} = 'active' THEN 1 ELSE 0 END) as active_subscribed_properties")
            ->selectRaw("SUM(CASE WHEN {$statusExpression} = 'expired' THEN 1 ELSE 0 END) as expired_properties")
            ->selectRaw("SUM(CASE WHEN {$statusExpression} = 'unsubscribed' THEN 1 ELSE 0 END) as unsubscribed_properties")
            ->groupBy('workspace_properties.tenant_id');

        $query = Tenant::query()
            ->leftJoinSub($paymentSummary, 'payment_summary', 'payment_summary.tenant_id', '=', 'tenants.id')
            ->leftJoinSub($propertySummary, 'property_summary', 'property_summary.tenant_id', '=', 'tenants.id')
            ->select([
                'tenants.uuid',
                'tenants.name',
                'tenants.display_name',
                'tenants.status',
                'tenants.provisioning_status',
                'tenants.created_at',
                'tenants.updated_at',
            ])
            ->selectRaw('COALESCE(property_summary.total_properties, 0) as total_properties')
            ->selectRaw('COALESCE(property_summary.active_subscribed_properties, 0) as active_subscribed_properties')
            ->selectRaw('COALESCE(property_summary.expired_properties, 0) as expired_properties')
            ->selectRaw('COALESCE(property_summary.unsubscribed_properties, 0) as unsubscribed_properties')
            ->selectRaw('COALESCE(payment_summary.payments_count, 0) as payments_count')
            ->selectRaw('COALESCE(payment_summary.total_collected_amount_cents, 0) as total_collected_amount_cents');

        if (!empty($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);
            $query->where(function (EloquentBuilder $builder) use ($search) {
                $this->applyPrefixSearch($builder, 'tenants.display_name', $search)
                    ->orWhere(function (EloquentBuilder $innerBuilder) use ($search) {
                        $this->applyPrefixSearch($innerBuilder, 'tenants.name', $search);
                    });
            });
        }

        if (!empty($filters['workspace_status'] ?? null)) {
            $query->where('tenants.status', $filters['workspace_status']);
        }

        $this->applyWorkspaceReportSort($query, $filters['sort'] ?? null);
        $rows = $query->paginate((int) ($filters['per_page'] ?? 15))->withQueryString();

        $totals = $this->cachedWorkspaceReportTotals($filters, $paymentSummary, $propertySummary);

        return [
            'filters' => [
                'search' => $filters['search'] ?? null,
                'workspace_status' => $filters['workspace_status'] ?? null,
                'start_date' => $filters['start_date'] ?? null,
                'end_date' => $filters['end_date'] ?? null,
                'sort' => $filters['sort'] ?? null,
            ],
            'totals' => [
                'workspaces_count' => (int) ($totals->workspaces_count ?? 0),
                'total_properties' => (int) ($totals->total_properties ?? 0),
                'active_subscribed_properties' => (int) ($totals->active_subscribed_properties ?? 0),
                'expired_properties' => (int) ($totals->expired_properties ?? 0),
                'unsubscribed_properties' => (int) ($totals->unsubscribed_properties ?? 0),
                'payments_count' => (int) ($totals->payments_count ?? 0),
                'total_collected_amount_cents' => (int) ($totals->total_collected_amount_cents ?? 0),
            ],
            'rows' => $rows,
        ];
    }

    public function expiredPropertiesReport(array $filters = []): LengthAwarePaginator
    {
        $query = DB::connection('base')->table('workspace_properties')
            ->join('tenants', 'tenants.id', '=', 'workspace_properties.tenant_id')
            ->leftJoin('property_subscriptions', 'property_subscriptions.workspace_property_id', '=', 'workspace_properties.id')
            ->leftJoin('billing_rules', 'billing_rules.id', '=', 'property_subscriptions.billing_rule_id')
            ->leftJoin('billing_profiles', 'billing_profiles.id', '=', 'billing_rules.billing_profile_id')
            ->whereNull('workspace_properties.property_deleted_at')
            ->where(function ($builder) {
                $builder
                    ->whereNull('property_subscriptions.id')
                    ->orWhere('property_subscriptions.status', PropertySubscription::STATUS_UNSUBSCRIBED)
                    ->orWhere('property_subscriptions.status', PropertySubscription::STATUS_EXPIRED)
                    ->orWhere(function ($expiredBuilder) {
                        $expiredBuilder
                            ->where('property_subscriptions.status', PropertySubscription::STATUS_ACTIVE)
                            ->whereNotNull('property_subscriptions.current_period_ends_on')
                            ->where('property_subscriptions.current_period_ends_on', '<', Carbon::today()->toDateString());
                    });
            })
            ->select([
                'tenants.uuid as workspace_uuid',
                'tenants.name as workspace_name',
                'tenants.display_name as workspace_display_name',
                'workspace_properties.property_uuid',
                'workspace_properties.property_name',
                'workspace_properties.property_status',
                'workspace_properties.current_registered_units_total',
                'property_subscriptions.uuid as subscription_uuid',
                'property_subscriptions.status as stored_status',
                'property_subscriptions.current_period_starts_on',
                'property_subscriptions.current_period_ends_on',
                'property_subscriptions.last_paid_on',
                'billing_rules.uuid as billing_rule_uuid',
                'billing_rules.range_start',
                'billing_rules.range_end',
                'billing_rules.price_cents',
                'billing_profiles.currency',
            ])
            ->selectRaw($this->effectiveStatusExpression('property_subscriptions').' as effective_status');

        if (!empty($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);
            $query->where(function ($builder) use ($search) {
                $this->applyPrefixSearch($builder, 'workspace_properties.property_name', $search)
                    ->orWhere(function ($displayNameBuilder) use ($search) {
                        $this->applyPrefixSearch($displayNameBuilder, 'tenants.display_name', $search);
                    })
                    ->orWhere(function ($nameBuilder) use ($search) {
                        $this->applyPrefixSearch($nameBuilder, 'tenants.name', $search);
                    });
            });
        }

        if (!empty($filters['status'] ?? null)) {
            $this->applyExpiredPropertiesStatusFilter($query, (string) $filters['status']);
        }

        $this->applyExpiredPropertySort($query, $filters['sort'] ?? null);

        return $query
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();
    }

    public function syncExpiredPropertySubscriptions(): int
    {
        return PropertySubscription::query()
            ->where('status', PropertySubscription::STATUS_ACTIVE)
            ->whereNotNull('current_period_ends_on')
            ->where('current_period_ends_on', '<', Carbon::today()->toDateString())
            ->update([
                'status' => PropertySubscription::STATUS_EXPIRED,
                'expired_on' => DB::raw('current_period_ends_on'),
                'updated_at' => now(),
            ]);
    }

    private function resolvePayableWorkspaceProperty(Tenant $tenant, string $propertyUuid): WorkspaceProperty
    {
        $workspaceProperty = $this->workspacePropertyRegistryService->resolveWorkspaceProperty($tenant, $propertyUuid);

        if (!$workspaceProperty || $workspaceProperty->tenant_id !== $tenant->id) {
            throw new InvalidArgumentException('The selected property could not be found for this workspace.');
        }

        if ($workspaceProperty->property_deleted_at) {
            throw new InvalidArgumentException('Payments cannot be recorded for a deleted property.');
        }

        return $workspaceProperty;
    }

    private function resolveBillableRule(string $billingRuleUuid, string $paymentDate): BillingRule
    {
        $paymentDate = Carbon::parse($paymentDate)->toDateString();

        $billingRule = BillingRule::query()
            ->with('profile:id,uuid,name,billing_interval,currency,status')
            ->where('uuid', $billingRuleUuid)
            ->where('status', 'active')
            ->where('effective_from', '<=', $paymentDate)
            ->where(function (EloquentBuilder $builder) use ($paymentDate) {
                $builder
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $paymentDate);
            })
            ->first();

        if (!$billingRule || !$billingRule->profile || $billingRule->profile->status !== 'active') {
            throw new InvalidArgumentException('The selected billing rule is not active for the provided payment date.');
        }

        return $billingRule;
    }

    private function resolveCoverage(Tenant $tenant, ?PropertySubscription $subscription, CarbonInterface $paymentDate, int $monthsPaid): array
    {
        $paymentStart = Carbon::parse($paymentDate)->startOfDay();
        $startsFromPaymentDate = true;
        $workspaceTrialEndsOn = $this->propertySubscriptionAccessService->activeWorkspaceTrialEndsOn($tenant, $paymentStart);

        if ($workspaceTrialEndsOn && $workspaceTrialEndsOn->gte($paymentStart)) {
            $paymentStart = $workspaceTrialEndsOn->copy()->addDay()->startOfDay();
            $startsFromPaymentDate = false;
        }

        if ($subscription && $subscription->effectiveStatus($paymentStart) === PropertySubscription::STATUS_ACTIVE) {
            $currentPeriodEndsOn = $subscription->current_period_ends_on
                ? Carbon::parse($subscription->current_period_ends_on)->startOfDay()
                : null;

            if ($currentPeriodEndsOn && $currentPeriodEndsOn->gte($paymentStart)) {
                $paymentStart = $currentPeriodEndsOn->copy()->addDay()->startOfDay();
                $startsFromPaymentDate = false;
            }
        }

        $paymentEnd = $paymentStart->copy()->addMonthsNoOverflow($monthsPaid)->subDay()->startOfDay();

        return [
            'starts_on' => $paymentStart,
            'ends_on' => $paymentEnd,
            'starts_from_payment_date' => $startsFromPaymentDate,
        ];
    }

    private function formatSubscription(?PropertySubscription $subscription): ?array
    {
        if (!$subscription) {
            return [
                'status' => PropertySubscription::STATUS_UNSUBSCRIBED,
                'effective_status' => PropertySubscription::STATUS_UNSUBSCRIBED,
                'current_period_starts_on' => null,
                'current_period_ends_on' => null,
                'last_paid_on' => null,
            ];
        }

        return [
            'uuid' => $subscription->uuid,
            'status' => $subscription->status,
            'effective_status' => $subscription->effectiveStatus(),
            'current_period_starts_on' => optional($subscription->current_period_starts_on)->toDateString(),
            'current_period_ends_on' => optional($subscription->current_period_ends_on)->toDateString(),
            'last_paid_on' => optional($subscription->last_paid_on)->toDateString(),
        ];
    }

    private function applySubscriptionStatusFilter(EloquentBuilder $query, string $status, string $alias): void
    {
        match ($status) {
            PropertySubscription::STATUS_ACTIVE => $query
                ->where("{$alias}.status", PropertySubscription::STATUS_ACTIVE)
                ->where(function (EloquentBuilder $builder) use ($alias) {
                    $builder
                        ->whereNull("{$alias}.current_period_ends_on")
                        ->orWhere("{$alias}.current_period_ends_on", '>=', Carbon::today()->toDateString());
                }),
            PropertySubscription::STATUS_EXPIRED => $query->where(function (EloquentBuilder $builder) use ($alias) {
                $builder
                    ->where("{$alias}.status", PropertySubscription::STATUS_EXPIRED)
                    ->orWhere(function (EloquentBuilder $innerQuery) use ($alias) {
                        $innerQuery
                            ->where("{$alias}.status", PropertySubscription::STATUS_ACTIVE)
                            ->where("{$alias}.current_period_ends_on", '<', Carbon::today()->toDateString());
                    });
            }),
            PropertySubscription::STATUS_UNSUBSCRIBED => $query->where(function (EloquentBuilder $builder) use ($alias) {
                $builder
                    ->whereNull("{$alias}.id")
                    ->orWhere("{$alias}.status", PropertySubscription::STATUS_UNSUBSCRIBED);
            }),
            default => null,
        };
    }

    private function applyTenantPropertySubscriptionSort(EloquentBuilder $query, ?string $sort): void
    {
        $sort = trim((string) $sort);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        match ($key) {
            'current_registered_units_total' => $query->orderBy('workspace_properties.current_registered_units_total', $direction)->orderBy('workspace_properties.property_name'),
            'current_period_ends_on' => $query->orderBy('property_subscription_sort.current_period_ends_on', $direction)->orderBy('workspace_properties.property_name'),
            'subscription_status' => $query
                ->orderByRaw($this->effectiveStatusOrderExpression('property_subscription_sort', $direction))
                ->orderBy('workspace_properties.property_name'),
            'created_at' => $query->orderBy('workspace_properties.created_at', $direction),
            'name', 'property_name' => $query->orderBy('workspace_properties.property_name', $direction),
            default => $query->orderBy('workspace_properties.property_name', $direction),
        };
    }

    private function applyPaymentSort(EloquentBuilder $query, ?string $sort): void
    {
        $sort = trim((string) $sort);
        if ($sort === '') {
            $query->orderByDesc('payment_date')->orderByDesc('created_at');

            return;
        }

        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        match ($key) {
            'total_amount_cents' => $query->orderBy('total_amount_cents', $direction)->orderByDesc('created_at'),
            'coverage_ends_on' => $query->orderBy('coverage_ends_on', $direction)->orderByDesc('created_at'),
            'created_at' => $query->orderBy('created_at', $direction),
            default => $query->orderBy('payment_date', $direction)->orderByDesc('created_at'),
        };
    }

    private function resolveActivePayment(WorkspaceProperty $workspaceProperty): ?PropertySubscriptionPayment
    {
        return $workspaceProperty->payments()
            ->with($this->paymentRelations())
            ->where('coverage_starts_on', '<=', Carbon::today()->toDateString())
            ->where('coverage_ends_on', '>=', Carbon::today()->toDateString())
            ->orderByDesc('coverage_ends_on')
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at')
            ->first();
    }

    private function cachedWorkspaceReportTotals(array $filters, $paymentSummary, $propertySummary): object
    {
        $cacheKey = 'billing.workspace_report.totals.'.md5(json_encode([
            'search' => $filters['search'] ?? null,
            'workspace_status' => $filters['workspace_status'] ?? null,
            'start_date' => $filters['start_date'] ?? null,
            'end_date' => $filters['end_date'] ?? null,
        ]));

        return Cache::remember($cacheKey, now()->addSeconds(self::WORKSPACE_REPORT_TOTALS_CACHE_TTL_SECONDS), function () use ($filters, $paymentSummary, $propertySummary) {
            return DB::connection('base')->table('tenants')
                ->leftJoinSub($paymentSummary, 'payment_summary', 'payment_summary.tenant_id', '=', 'tenants.id')
                ->leftJoinSub($propertySummary, 'property_summary', 'property_summary.tenant_id', '=', 'tenants.id')
                ->when(!empty($filters['search'] ?? null), function ($builder) use ($filters) {
                    $search = trim((string) $filters['search']);
                    $builder->where(function ($innerQuery) use ($search) {
                        $this->applyPrefixSearch($innerQuery, 'tenants.display_name', $search)
                            ->orWhere(function ($nameQuery) use ($search) {
                                $this->applyPrefixSearch($nameQuery, 'tenants.name', $search);
                            });
                    });
                })
                ->when(!empty($filters['workspace_status'] ?? null), fn ($builder) => $builder->where('tenants.status', $filters['workspace_status']))
                ->selectRaw('COUNT(tenants.id) as workspaces_count')
                ->selectRaw('COALESCE(SUM(property_summary.total_properties), 0) as total_properties')
                ->selectRaw('COALESCE(SUM(property_summary.active_subscribed_properties), 0) as active_subscribed_properties')
                ->selectRaw('COALESCE(SUM(property_summary.expired_properties), 0) as expired_properties')
                ->selectRaw('COALESCE(SUM(property_summary.unsubscribed_properties), 0) as unsubscribed_properties')
                ->selectRaw('COALESCE(SUM(payment_summary.payments_count), 0) as payments_count')
                ->selectRaw('COALESCE(SUM(payment_summary.total_collected_amount_cents), 0) as total_collected_amount_cents')
                ->first();
        });
    }

    private function applyExpiredPropertiesStatusFilter($query, string $status): void
    {
        match ($status) {
            PropertySubscription::STATUS_UNSUBSCRIBED => $query->where(function ($builder) {
                $builder
                    ->whereNull('property_subscriptions.id')
                    ->orWhere('property_subscriptions.status', PropertySubscription::STATUS_UNSUBSCRIBED);
            }),
            PropertySubscription::STATUS_EXPIRED => $query->where(function ($builder) {
                $builder
                    ->where('property_subscriptions.status', PropertySubscription::STATUS_EXPIRED)
                    ->orWhere(function ($innerBuilder) {
                        $innerBuilder
                            ->where('property_subscriptions.status', PropertySubscription::STATUS_ACTIVE)
                            ->whereNotNull('property_subscriptions.current_period_ends_on')
                            ->where('property_subscriptions.current_period_ends_on', '<', Carbon::today()->toDateString());
                    });
            }),
            default => null,
        };
    }

    private function applyPrefixSearch($query, string $column, string $search)
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        if ($this->usesPostgresCaseSensitiveLike()) {
            return $query->whereRaw('LOWER('.$column.') LIKE ?', [mb_strtolower($search, 'UTF-8').'%']);
        }

        return $query->where($column, 'like', $search.'%');
    }

    private function usesPostgresCaseSensitiveLike(): bool
    {
        return DB::connection('base')->getDriverName() === 'pgsql';
    }

    private function paymentRelations(): array
    {
        return [
            'workspaceProperty:id,uuid,tenant_id,property_uuid,property_name,property_status,current_registered_units_total,property_deleted_at',
            'propertySubscription:id,uuid,workspace_property_id,status,current_period_starts_on,current_period_ends_on,last_paid_on',
            'billingRule:id,uuid,billing_profile_id,range_start,range_end,price_cents,status,effective_from,effective_to',
            'billingRule.profile:id,uuid,name,billing_interval,currency,status',
        ];
    }

    private function applyWorkspaceReportSort(EloquentBuilder $query, ?string $sort): void
    {
        $sort = trim((string) $sort);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        match ($key) {
            'workspace_name' => $query->orderBy('tenants.display_name', $direction)->orderBy('tenants.name'),
            'total_properties' => $query->orderBy('total_properties', $direction)->orderBy('tenants.display_name'),
            'active_subscribed_properties' => $query->orderBy('active_subscribed_properties', $direction)->orderBy('tenants.display_name'),
            'expired_properties' => $query->orderBy('expired_properties', $direction)->orderBy('tenants.display_name'),
            'unsubscribed_properties' => $query->orderBy('unsubscribed_properties', $direction)->orderBy('tenants.display_name'),
            'total_collected_amount_cents' => $query->orderBy('total_collected_amount_cents', $direction)->orderBy('tenants.display_name'),
            'payments_count' => $query->orderBy('payments_count', $direction)->orderBy('tenants.display_name'),
            'workspace_status' => $query->orderBy('tenants.status', $direction)->orderBy('tenants.display_name'),
            default => $query->orderBy('tenants.display_name', $direction)->orderBy('tenants.name'),
        };
    }

    private function applyExpiredPropertySort($query, ?string $sort): void
    {
        $sort = trim((string) $sort);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        match ($key) {
            'workspace_name' => $query->orderBy('workspace_display_name', $direction)->orderBy('property_name'),
            'current_period_ends_on' => $query->orderBy('current_period_ends_on', $direction)->orderBy('workspace_display_name'),
            'current_registered_units_total' => $query->orderBy('current_registered_units_total', $direction)->orderBy('workspace_display_name'),
            default => $query->orderBy('workspace_display_name')->orderBy('property_name'),
        };
    }

    private function effectiveStatusExpression(string $alias): string
    {
        return "CASE
            WHEN {$alias}.status = 'active' AND {$alias}.current_period_ends_on IS NOT NULL AND {$alias}.current_period_ends_on < CURRENT_DATE THEN 'expired'
            WHEN {$alias}.status IS NULL THEN 'unsubscribed'
            ELSE {$alias}.status
        END";
    }

    private function effectiveStatusOrderExpression(string $alias, string $direction): string
    {
        $expression = $this->effectiveStatusExpression($alias);
        $orderMap = "CASE {$expression}
            WHEN 'active' THEN 1
            WHEN 'expired' THEN 2
            ELSE 3
        END";

        return "{$orderMap} {$direction}";
    }
}
