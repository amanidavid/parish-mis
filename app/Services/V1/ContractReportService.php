<?php

namespace App\Services\V1;

use App\Models\Tenant\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ContractReportService
{
    private const MAX_PER_PAGE = 100;

    /**
     * Create a new instance.
     */
    public function __construct(
        private PropertyAssignmentAccessService $propertyAssignmentAccessService,
    ) {
    }

    /**
     * Handle the summary request.
     */
    public function summary(User $tenantUser, array $filters = []): array
    {
        $scope = $this->resolveScope($tenantUser);
        $query = $this->contractsBaseQuery($scope, $filters);

        $statusRows = (clone $query)
            ->select('customer_contracts.status')
            ->selectRaw('COUNT(customer_contracts.id) as contracts_count')
            ->selectRaw('COALESCE(SUM(customer_contracts.amount), 0) as total_contract_amount')
            ->groupBy('customer_contracts.status')
            ->orderBy('customer_contracts.status')
            ->get();

        $statusBreakdown = $statusRows
            ->map(fn ($row) => [
                'status' => $row->status,
                'contracts_count' => (int) $row->contracts_count,
                'total_contract_amount' => (float) $row->total_contract_amount,
            ])
            ->values()
            ->all();

        $totals = [
            'contracts_count' => (int) $statusRows->sum(fn ($row) => (int) $row->contracts_count),
            'total_contract_amount' => (float) $statusRows->sum(fn ($row) => (float) $row->total_contract_amount),
            'active_contracts_count' => (int) optional($statusRows->firstWhere('status', 'active'))->contracts_count,
            'active_contract_amount' => (float) optional($statusRows->firstWhere('status', 'active'))->total_contract_amount,
            'draft_contracts_count' => (int) optional($statusRows->firstWhere('status', 'draft'))->contracts_count,
            'expired_contracts_count' => (int) optional($statusRows->firstWhere('status', 'expired'))->contracts_count,
            'terminated_contracts_count' => (int) optional($statusRows->firstWhere('status', 'terminated'))->contracts_count,
            'renewed_contracts_count' => (int) optional($statusRows->firstWhere('status', 'renewed'))->contracts_count,
        ];

        return [
            'filters' => [
                'property_uuid' => $filters['property_uuid'] ?? null,
                'customer_uuid' => $filters['customer_uuid'] ?? null,
                'status' => $filters['status'] ?? null,
                'billing_cycle' => $filters['billing_cycle'] ?? null,
                'start_date' => $filters['start_date'] ?? null,
                'end_date' => $filters['end_date'] ?? null,
            ],
            'totals' => $totals,
            'by_status' => $statusBreakdown,
        ];
    }

    /**
     * Handle the by property request.
     */
    public function byProperty(User $tenantUser, array $filters = []): LengthAwarePaginator
    {
        $scope = $this->resolveScope($tenantUser);

        $query = $this->contractsBaseQuery($scope, $filters, false)
            ->join('properties', 'properties.id', '=', 'property_floors.property_id')
            ->select([
                'properties.uuid as property_uuid',
                'properties.name as property_name',
                'properties.status as property_status',
            ])
            ->selectRaw('COUNT(customer_contracts.id) as contracts_count')
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'active' THEN 1 ELSE 0 END) as active_contracts_count")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'expired' THEN 1 ELSE 0 END) as expired_contracts_count")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'terminated' THEN 1 ELSE 0 END) as terminated_contracts_count")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'renewed' THEN 1 ELSE 0 END) as renewed_contracts_count")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'draft' THEN 1 ELSE 0 END) as draft_contracts_count")
            ->selectRaw('COALESCE(SUM(customer_contracts.amount), 0) as total_contract_amount')
            ->selectRaw("COALESCE(SUM(CASE WHEN customer_contracts.status = 'active' THEN customer_contracts.amount ELSE 0 END), 0) as active_contract_amount")
            ->selectRaw('MAX(customer_contracts.end_date) as latest_end_date')
            ->groupBy('properties.id', 'properties.uuid', 'properties.name', 'properties.status');

        if (!empty($filters['search'] ?? null)) {
            $query->where('properties.name', 'like', $filters['search'].'%');
        }

        $this->applyByPropertySort($query, $filters['sort'] ?? null);

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), self::MAX_PER_PAGE);

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Handle the expiring request.
     */
    public function expiring(User $tenantUser, array $filters = []): LengthAwarePaginator
    {
        $scope = $this->resolveScope($tenantUser);
        $query = $this->contractsBaseQuery($scope, $filters)
            ->join('properties', 'properties.id', '=', 'property_floors.property_id')
            ->join('customers', 'customers.id', '=', 'customer_contracts.customer_id')
            ->select([
                'customer_contracts.uuid as contract_uuid',
                'customer_contracts.contract_number',
                'customer_contracts.status',
                'customer_contracts.billing_cycle',
                'customer_contracts.amount',
                'customer_contracts.currency',
                'customer_contracts.start_date',
                'customer_contracts.end_date',
                'customers.uuid as customer_uuid',
                'customers.display_name as customer_name',
                'properties.uuid as property_uuid',
                'properties.name as property_name',
                'units.uuid as unit_uuid',
                'units.unit_number',
            ])
            ->whereNotNull('customer_contracts.end_date');

        if (empty($filters['status'] ?? null)) {
            $query->whereIn('customer_contracts.status', ['active', 'renewed']);
        }

        [$expiryStartDate, $expiryEndDate] = $this->resolveExpiryWindow($filters);
        $query->whereBetween('customer_contracts.end_date', [$expiryStartDate, $expiryEndDate]);

        if (!empty($filters['search'] ?? null)) {
            $query->where(function (QueryBuilder $innerQuery) use ($filters) {
                $innerQuery
                    ->where('customer_contracts.contract_number', 'like', $filters['search'].'%')
                    ->orWhere('customers.display_name', 'like', $filters['search'].'%')
                    ->orWhere('properties.name', 'like', $filters['search'].'%');
            });
        }

        $driver = DB::connection($this->tenantConnectionName())->getDriverName();
        $daysToExpiryExpression = $driver === 'pgsql'
            ? 'customer_contracts.end_date - CURRENT_DATE'
            : 'DATEDIFF(customer_contracts.end_date, CURRENT_DATE)';

        $query->addSelect(DB::raw($daysToExpiryExpression.' as days_to_expiry'));

        $this->applyExpiringSort($query, $filters['sort'] ?? null);

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), self::MAX_PER_PAGE);

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Handle the chart request.
     */
    public function chart(User $tenantUser, array $filters = []): array
    {
        [$startDate, $endDate, $range] = $this->resolveChartWindow($filters);
        $period = $filters['period'] ?? $this->defaultChartPeriodForRange($range);
        $scope = $this->resolveScope($tenantUser);

        $property = $this->tenantTable('properties')
            ->where('uuid', $filters['property_uuid'])
            ->select(['id', 'uuid', 'name', 'status'])
            ->when($scope['bypass'] !== true, fn (QueryBuilder $propertyQuery) => $propertyQuery->whereExists(function (QueryBuilder $innerQuery) use ($scope) {
                $innerQuery->selectRaw('1')
                    ->from('staff_property_assignments')
                    ->whereColumn('staff_property_assignments.property_id', 'properties.id')
                    ->where('staff_property_assignments.user_id', $scope['user_id']);
            }))
            ->first();

        abort_if(!$property, 404, 'Property not found.');

        $query = $this->tenantTable('customer_contracts')
            ->join('units', 'units.id', '=', 'customer_contracts.unit_id')
            ->join('property_floors', 'property_floors.id', '=', 'units.property_floor_id')
            ->where('property_floors.property_id', $property->id)
            ->whereBetween('customer_contracts.start_date', [$startDate, $endDate]);

        $query = $this->applyPropertyScopeToColumn($query, $scope, 'property_floors.property_id');

        if (!empty($filters['billing_cycle'] ?? null)) {
            $query->where('customer_contracts.billing_cycle', $filters['billing_cycle']);
        }

        [$bucketSql, $bucketLabelSql] = $this->chartBucketExpressions($period);
        $recognizedStatuses = ['active', 'renewed'];

        // We aggregate once by chart bucket, then derive summary totals from those rows
        // to avoid running the same filtered contract scan twice.
        $series = (clone $query)
            ->selectRaw($bucketSql.' as bucket_key')
            ->selectRaw($bucketLabelSql.' as bucket_label')
            ->selectRaw('COUNT(customer_contracts.id) as contracts_count')
            ->selectRaw("SUM(CASE WHEN customer_contracts.status IN ('active', 'renewed') THEN 1 ELSE 0 END) as recognized_contracts_count")
            ->selectRaw('COALESCE(SUM(customer_contracts.amount), 0) as total_contract_amount')
            ->selectRaw("COALESCE(SUM(CASE WHEN customer_contracts.status IN ('active', 'renewed') THEN customer_contracts.amount ELSE 0 END), 0) as recognized_contract_amount")
            ->groupByRaw($bucketSql.', '.$bucketLabelSql)
            ->orderBy('bucket_key')
            ->get();

        $summary = [
            'contracts_count' => (int) $series->sum(fn ($row) => (int) $row->contracts_count),
            'recognized_contracts_count' => (int) $series->sum(fn ($row) => (int) $row->recognized_contracts_count),
            'total_contract_amount' => (float) $series->sum(fn ($row) => (float) $row->total_contract_amount),
            'recognized_contract_amount' => (float) $series->sum(fn ($row) => (float) $row->recognized_contract_amount),
        ];

        $series = $series
            ->map(fn ($row) => [
                'bucket_key' => $row->bucket_key,
                'bucket_label' => $row->bucket_label,
                'contracts_count' => (int) $row->contracts_count,
                'recognized_contracts_count' => (int) $row->recognized_contracts_count,
                'total_contract_amount' => (float) $row->total_contract_amount,
                'recognized_contract_amount' => (float) $row->recognized_contract_amount,
            ])
            ->values()
            ->all();

        return [
            'filters' => [
                'property_uuid' => $filters['property_uuid'],
                'billing_cycle' => $filters['billing_cycle'] ?? null,
                'range' => $range,
                'period' => $period,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'metric' => $filters['metric'] ?? 'recognized_contract_amount',
                'recognized_statuses' => $recognizedStatuses,
            ],
            'property' => [
                'uuid' => $property->uuid,
                'name' => $property->name,
                'status' => $property->status,
            ],
            'summary' => $summary,
            'series' => $series,
        ];
    }

    /**
     * Contracts base query.
     */
    private function contractsBaseQuery(
        array $scope,
        array $filters,
        bool $applyContractWindow = true
    ): QueryBuilder
    {
        $query = $this->tenantTable('customer_contracts')
            ->join('units', 'units.id', '=', 'customer_contracts.unit_id')
            ->join('property_floors', 'property_floors.id', '=', 'units.property_floor_id');

        $query = $this->applyPropertyScopeToColumn($query, $scope, 'property_floors.property_id');

        if (!empty($filters['property_uuid'] ?? null)) {
            $query->join('properties as filter_properties', 'filter_properties.id', '=', 'property_floors.property_id')
                ->where('filter_properties.uuid', $filters['property_uuid']);
        }

        if (!empty($filters['customer_uuid'] ?? null)) {
            $query->join('customers as filter_customers', 'filter_customers.id', '=', 'customer_contracts.customer_id')
                ->where('filter_customers.uuid', $filters['customer_uuid']);
        }

        if (!empty($filters['status'] ?? null)) {
            $query->where('customer_contracts.status', $filters['status']);
        }

        if (!empty($filters['billing_cycle'] ?? null)) {
            $query->where('customer_contracts.billing_cycle', $filters['billing_cycle']);
        }

        if (
            $applyContractWindow
            && (!empty($filters['start_date'] ?? null) || !empty($filters['end_date'] ?? null))
        ) {
            $startDate = $filters['start_date'] ?? $filters['end_date'];
            $endDate = $filters['end_date'] ?? $filters['start_date'];

            $query->where('customer_contracts.start_date', '<=', $endDate)
                ->where(function (QueryBuilder $innerQuery) use ($startDate) {
                    $innerQuery
                        ->whereNull('customer_contracts.end_date')
                        ->orWhere('customer_contracts.end_date', '>=', $startDate);
                });
        }

        return $query;
    }

    /**
     * Resolve scope.
     */
    private function resolveScope(User $tenantUser): array
    {
        return [
            'bypass' => $this->propertyAssignmentAccessService->canBypassPropertyScope($tenantUser),
            'user_id' => (int) $tenantUser->id,
        ];
    }

    /**
     * Apply by property sort.
     */
    private function applyByPropertySort(QueryBuilder $query, ?string $sort): void
    {
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $column = ltrim((string) $sort, '-');

        match ($column) {
            'contracts_count' => $query->orderBy('contracts_count', $direction)->orderBy('properties.name'),
            'total_contract_amount' => $query->orderBy('total_contract_amount', $direction)->orderBy('properties.name'),
            'active_contract_amount' => $query->orderBy('active_contract_amount', $direction)->orderBy('properties.name'),
            'latest_end_date' => $query->orderBy('latest_end_date', $direction)->orderBy('properties.name'),
            'name', '' => $query->orderBy('properties.name', $direction),
            default => $query->orderBy('properties.name'),
        };
    }

    /**
     * Apply expiring sort.
     */
    private function applyExpiringSort(QueryBuilder $query, ?string $sort): void
    {
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $column = ltrim((string) $sort, '-');

        match ($column) {
            'amount' => $query->orderBy('customer_contracts.amount', $direction)->orderBy('customer_contracts.end_date'),
            'contract_number' => $query->orderBy('customer_contracts.contract_number', $direction),
            'customer_name' => $query->orderBy('customers.display_name', $direction)->orderBy('customer_contracts.end_date'),
            'property_name' => $query->orderBy('properties.name', $direction)->orderBy('customer_contracts.end_date'),
            'end_date', '' => $query->orderBy('customer_contracts.end_date', $direction)->orderBy('customer_contracts.contract_number'),
            default => $query->orderBy('customer_contracts.end_date')->orderBy('customer_contracts.contract_number'),
        };
    }

    /**
     * Resolve expiry window.
     */
    private function resolveExpiryWindow(array $filters): array
    {
        $startDate = !empty($filters['start_date'] ?? null)
            ? Carbon::parse($filters['start_date'])->toDateString()
            : now()->toDateString();

        $endDate = !empty($filters['end_date'] ?? null)
            ? Carbon::parse($filters['end_date'])->toDateString()
            : now()->addDays((int) ($filters['days'] ?? 30))->toDateString();

        return [$startDate, $endDate];
    }

    /**
     * Resolve chart window.
     */
    private function resolveChartWindow(array $filters): array
    {
        $range = $filters['range'] ?? 'last_12_months';

        if ($range === 'custom') {
            return [
                Carbon::parse($filters['start_date'])->toDateString(),
                Carbon::parse($filters['end_date'])->toDateString(),
                $range,
            ];
        }

        $today = now()->startOfDay();

        return match ($range) {
            'today' => [$today->toDateString(), $today->toDateString(), $range],
            'last_7_days' => [$today->copy()->subDays(6)->toDateString(), $today->toDateString(), $range],
            'last_30_days' => [$today->copy()->subDays(29)->toDateString(), $today->toDateString(), $range],
            'this_month' => [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString(), $range],
            'this_year' => [$today->copy()->startOfYear()->toDateString(), $today->copy()->endOfYear()->toDateString(), $range],
            default => [$today->copy()->subMonths(11)->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString(), 'last_12_months'],
        };
    }

    /**
     * Default chart period for range.
     */
    private function defaultChartPeriodForRange(string $range): string
    {
        return match ($range) {
            'today', 'last_7_days', 'last_30_days' => 'day',
            'this_year', 'last_12_months' => 'month',
            default => 'month',
        };
    }

    /**
     * Chart bucket expressions.
     */
    private function chartBucketExpressions(string $period): array
    {
        $driver = DB::connection($this->tenantConnectionName())->getDriverName();

        if ($driver === 'pgsql') {
            return match ($period) {
                'day' => [
                    "TO_CHAR(customer_contracts.start_date, 'YYYY-MM-DD')",
                    "TO_CHAR(customer_contracts.start_date, 'DD Mon YYYY')",
                ],
                'year' => [
                    "TO_CHAR(customer_contracts.start_date, 'YYYY')",
                    "TO_CHAR(customer_contracts.start_date, 'YYYY')",
                ],
                default => [
                    "TO_CHAR(customer_contracts.start_date, 'YYYY-MM')",
                    "TO_CHAR(customer_contracts.start_date, 'Mon YYYY')",
                ],
            };
        }

        return match ($period) {
            'day' => [
                "DATE_FORMAT(customer_contracts.start_date, '%Y-%m-%d')",
                "DATE_FORMAT(customer_contracts.start_date, '%d %b %Y')",
            ],
            'year' => [
                "DATE_FORMAT(customer_contracts.start_date, '%Y')",
                "DATE_FORMAT(customer_contracts.start_date, '%Y')",
            ],
            default => [
                "DATE_FORMAT(customer_contracts.start_date, '%Y-%m')",
                "DATE_FORMAT(customer_contracts.start_date, '%b %Y')",
            ],
        };
    }

    /**
     * Apply property scope to column.
     */
    private function applyPropertyScopeToColumn(QueryBuilder $query, array $scope, string $propertyColumn): QueryBuilder
    {
        if ($scope['bypass'] === true) {
            return $query;
        }

        return $query->whereExists(function (QueryBuilder $innerQuery) use ($scope, $propertyColumn) {
            $innerQuery->selectRaw('1')
                ->from('staff_property_assignments')
                ->whereColumn('staff_property_assignments.property_id', $propertyColumn)
                ->where('staff_property_assignments.user_id', $scope['user_id']);
        });
    }

    /**
     * Tenant table.
     */
    private function tenantTable(string $table): QueryBuilder
    {
        return DB::connection($this->tenantConnectionName())->table($table);
    }

    /**
     * Tenant connection name.
     */
    private function tenantConnectionName(): string
    {
        return (string) config('multitenancy.tenant_database_connection_name', 'tenant');
    }
}
