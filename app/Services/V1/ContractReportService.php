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
    private const STATUS_ACTIVE = 'active';
    private const STATUS_DRAFT = 'draft';
    private const STATUS_EXPIRED = 'expired';
    private const STATUS_TERMINATED = 'terminated';
    private const TYPE_PAYMENT = 'payment';
    private const TYPE_REFUND = 'refund';

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

        $totalsRow = (clone $query)
            ->selectRaw('COUNT(customer_contracts.id) as contracts_count')
            ->selectRaw('COALESCE(SUM(customer_contracts.amount), 0) as total_contract_amount')
            ->selectRaw('COALESCE(SUM(customer_contracts.net_collected_amount), 0) as revenue_collected')
            ->selectRaw('COALESCE(SUM(customer_contracts.outstanding_balance), 0) as outstanding_contract_balance')
            ->selectRaw("COALESCE(SUM(CASE WHEN customer_contracts.payment_status = 'partial' THEN customer_contracts.outstanding_balance ELSE 0 END), 0) as remaining_debts_of_partial_paid_contracts")
            ->first();

        $statusBreakdown = $statusRows
            ->map(fn ($row) => [
                'status' => $row->status,
                'contracts_count' => (int) $row->contracts_count,
                'total_contract_amount' => (float) $row->total_contract_amount,
            ])
            ->values()
            ->all();

        return [
            'filters' => [
                'property_uuid' => $filters['property_uuid'] ?? null,
                'customer_uuid' => $filters['customer_uuid'] ?? null,
                'status' => $filters['status'] ?? null,
                'start_date' => $filters['start_date'] ?? null,
                'end_date' => $filters['end_date'] ?? null,
            ],
            'totals' => [
                'contracts_count' => (int) ($totalsRow->contracts_count ?? 0),
                'total_contract_amount' => (float) ($totalsRow->total_contract_amount ?? 0),
                'revenue_collected' => (float) ($totalsRow->revenue_collected ?? 0),
                'outstanding_contract_balance' => (float) ($totalsRow->outstanding_contract_balance ?? 0),
                'remaining_debts_of_partial_paid_contracts' => (float) ($totalsRow->remaining_debts_of_partial_paid_contracts ?? 0),
                'active_contracts_count' => $this->statusRowCount($statusRows, self::STATUS_ACTIVE),
                'draft_contracts_count' => $this->statusRowCount($statusRows, self::STATUS_DRAFT),
                'expired_contracts_count' => $this->statusRowCount($statusRows, self::STATUS_EXPIRED),
                'terminated_contracts_count' => $this->statusRowCount($statusRows, self::STATUS_TERMINATED),
            ],
            'by_status' => $statusBreakdown,
        ];
    }

    /**
     * Build summary cards using contract snapshots for balances and transaction dates for revenue.
     */
    public function summaryCards(User $tenantUser, array $filters = []): array
    {
        $scope = $this->resolveScope($tenantUser);
        [$startDate, $endDate, $range] = $this->resolveSummaryCardsWindow($filters);

        $contractsQuery = $this->tenantTable('customer_contracts')
            ->join('units', 'units.id', '=', 'customer_contracts.unit_id')
            ->join('property_floors', 'property_floors.id', '=', 'units.property_floor_id')
            ->where('customer_contracts.start_date', '<=', $endDate)
            ->where(function (QueryBuilder $innerQuery) use ($startDate) {
                $innerQuery
                    ->whereNull('customer_contracts.end_date')
                    ->orWhere('customer_contracts.end_date', '>=', $startDate);
            });

        $contractsQuery = $this->applyPropertyScopeToColumn($contractsQuery, $scope, 'property_floors.property_id');
        $contractsQuery = $this->applyContractPropertyFilter($contractsQuery, $filters);

        $contractTotals = (clone $contractsQuery)
            ->selectRaw('COUNT(customer_contracts.id) as total_contracts')
            ->selectRaw("COALESCE(SUM(CASE WHEN customer_contracts.payment_status = 'partial' THEN customer_contracts.outstanding_balance ELSE 0 END), 0) as remaining_debts_of_partial_paid_contracts")
            ->selectRaw('COALESCE(SUM(customer_contracts.outstanding_balance), 0) as outstanding_contract_balance')
            ->first();

        $revenueQuery = $this->contractTransactionsBaseQuery($scope, ['property_uuid' => $filters['property_uuid'] ?? null])
            ->whereBetween('customer_contract_transactions.transaction_date', [$startDate, $endDate]);

        $revenueTotals = (clone $revenueQuery)
            ->selectRaw("COALESCE(SUM(CASE WHEN customer_contract_transactions.type = 'payment' THEN customer_contract_transactions.amount ELSE 0 END), 0) as gross_collected_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN customer_contract_transactions.type = 'refund' THEN customer_contract_transactions.amount ELSE 0 END), 0) as refund_amount")
            ->first();

        $expensesQuery = $this->tenantTable('maintenance_expenses')
            ->join('maintenance_jobs', 'maintenance_jobs.id', '=', 'maintenance_expenses.maintenance_job_id')
            ->whereBetween('maintenance_expenses.expense_date', [$startDate, $endDate]);

        $expensesQuery = $this->applyPropertyScopeToColumn($expensesQuery, $scope, 'maintenance_jobs.property_id');
        $expensesQuery = $this->applyExpensePropertyFilter($expensesQuery, $filters);

        $expenseTotals = (clone $expensesQuery)
            ->selectRaw('COALESCE(SUM(maintenance_expenses.amount), 0) as total_expenses')
            ->first();

        $grossCollected = (float) ($revenueTotals->gross_collected_amount ?? 0);
        $refundAmount = (float) ($revenueTotals->refund_amount ?? 0);
        $revenueCollected = $grossCollected - $refundAmount;
        $totalExpenses = (float) ($expenseTotals->total_expenses ?? 0);
        $remainingDebts = (float) ($contractTotals->outstanding_contract_balance ?? 0);

        return [
            'filters' => [
                'property_uuid' => $filters['property_uuid'] ?? null,
                'range' => $range,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'reporting_rule' => 'contract_and_transaction_based',
            ],
            'summary_cards' => [
                'total_contracts' => (int) ($contractTotals->total_contracts ?? 0),
                'revenue_collected' => $revenueCollected,
                'total_expenses' => $totalExpenses,
                'remaining' => $revenueCollected - $totalExpenses,
                'remaining_debts' => $remainingDebts,
            ],
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
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'draft' THEN 1 ELSE 0 END) as draft_contracts_count")
            ->selectRaw('COALESCE(SUM(customer_contracts.amount), 0) as total_contract_amount')
            ->selectRaw('COALESCE(SUM(customer_contracts.net_collected_amount), 0) as revenue_collected')
            ->selectRaw('COALESCE(SUM(customer_contracts.outstanding_balance), 0) as outstanding_contract_balance')
            ->selectRaw("COALESCE(SUM(CASE WHEN customer_contracts.payment_status = 'partial' THEN customer_contracts.outstanding_balance ELSE 0 END), 0) as remaining_debts_of_partial_paid_contracts")
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
            $query->where('customer_contracts.status', 'active');
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

        $query = $this->contractTransactionsBaseQuery($scope, ['property_uuid' => $filters['property_uuid']])
            ->whereBetween('customer_contract_transactions.transaction_date', [$startDate, $endDate]);

        [$bucketSql, $bucketLabelSql] = $this->transactionBucketExpressions($period);

        $series = $query
            ->selectRaw($bucketSql.' as bucket_key')
            ->selectRaw($bucketLabelSql.' as bucket_label')
            ->selectRaw('COUNT(DISTINCT customer_contracts.id) as contracts_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN customer_contract_transactions.type = 'payment' THEN customer_contract_transactions.amount ELSE 0 END), 0) as gross_collected_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN customer_contract_transactions.type = 'refund' THEN customer_contract_transactions.amount ELSE 0 END), 0) as refund_amount")
            ->groupByRaw($bucketSql.', '.$bucketLabelSql)
            ->orderBy('bucket_key')
            ->get()
            ->map(function ($row) {
                $grossCollected = (float) $row->gross_collected_amount;
                $refundAmount = (float) $row->refund_amount;

                return [
                    'bucket_key' => $row->bucket_key,
                    'bucket_label' => $row->bucket_label,
                    'contracts_count' => (int) $row->contracts_count,
                    'gross_collected_amount' => $grossCollected,
                    'refund_amount' => $refundAmount,
                    'revenue_collected' => $grossCollected - $refundAmount,
                ];
            })
            ->values()
            ->all();

        return [
            'filters' => [
                'property_uuid' => $filters['property_uuid'],
                'range' => $range,
                'period' => $period,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'metric' => $filters['metric'] ?? 'revenue_collected',
            ],
            'property' => [
                'uuid' => $property->uuid,
                'name' => $property->name,
                'status' => $property->status,
            ],
            'summary' => [
                'contracts_count' => (int) collect($series)->sum('contracts_count'),
                'gross_collected_amount' => (float) collect($series)->sum('gross_collected_amount'),
                'refund_amount' => (float) collect($series)->sum('refund_amount'),
                'revenue_collected' => (float) collect($series)->sum('revenue_collected'),
            ],
            'series' => $series,
        ];
    }

    /**
     * Build the rolling revenue chart using contract transaction dates.
     */
    public function monthlyActiveAmountChart(User $tenantUser, array $filters = []): array
    {
        $scope = $this->resolveScope($tenantUser);
        $windowEnd = now()->startOfMonth();
        $windowStart = $windowEnd->copy()->subMonths(11);
        $startDate = $windowStart->toDateString();
        $endDate = $windowEnd->copy()->endOfMonth()->toDateString();

        $query = $this->contractTransactionsBaseQuery($scope, ['property_uuid' => $filters['property_uuid'] ?? null])
            ->whereBetween('customer_contract_transactions.transaction_date', [$startDate, $endDate]);

        [$bucketSql, $bucketLabelSql] = $this->monthlyTransactionBucketExpressions();

        $rows = $query
            ->selectRaw($bucketSql.' as bucket_key')
            ->selectRaw($bucketLabelSql.' as bucket_label')
            ->selectRaw("COALESCE(SUM(CASE WHEN customer_contract_transactions.type = 'payment' THEN customer_contract_transactions.amount ELSE 0 END), 0) as gross_collected_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN customer_contract_transactions.type = 'refund' THEN customer_contract_transactions.amount ELSE 0 END), 0) as refund_amount")
            ->groupByRaw($bucketSql.', '.$bucketLabelSql)
            ->orderBy('bucket_key')
            ->get()
            ->keyBy('bucket_key');

        $series = collect(range(0, 11))
            ->map(function (int $offset) use ($windowStart, $rows): array {
                $bucketStart = $windowStart->copy()->addMonths($offset);
                $bucketKey = $bucketStart->toDateString();
                $row = $rows->get($bucketKey);
                $grossCollected = (float) ($row->gross_collected_amount ?? 0);
                $refundAmount = (float) ($row->refund_amount ?? 0);

                return [
                    'bucket_key' => $bucketKey,
                    'bucket_label' => $bucketStart->format('M Y'),
                    'month' => (int) $bucketStart->format('n'),
                    'year' => (int) $bucketStart->format('Y'),
                    'gross_collected_amount' => $grossCollected,
                    'refund_amount' => $refundAmount,
                    'revenue_collected' => $grossCollected - $refundAmount,
                ];
            })
            ->values()
            ->all();

        return [
            'filters' => [
                'property_uuid' => $filters['property_uuid'] ?? null,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'window' => 'last_12_months',
                'window_start_month' => $windowStart->toDateString(),
                'window_end_month' => $windowEnd->toDateString(),
                'reporting_rule' => 'transaction_date_based',
                'metric' => 'revenue_collected',
            ],
            'summary' => [
                'gross_collected_amount' => (float) collect($series)->sum('gross_collected_amount'),
                'refund_amount' => (float) collect($series)->sum('refund_amount'),
                'revenue_collected' => (float) collect($series)->sum('revenue_collected'),
            ],
            'series' => $series,
        ];
    }

    /**
     * Contracts base query.
     */
    private function contractsBaseQuery(array $scope, array $filters, bool $applyContractWindow = true): QueryBuilder
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

        if ($applyContractWindow && (!empty($filters['start_date'] ?? null) || !empty($filters['end_date'] ?? null))) {
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
     * Contract transactions base query.
     */
    private function contractTransactionsBaseQuery(array $scope, array $filters = []): QueryBuilder
    {
        $query = $this->tenantTable('customer_contract_transactions')
            ->join('customer_contracts', 'customer_contracts.id', '=', 'customer_contract_transactions.customer_contract_id')
            ->join('units', 'units.id', '=', 'customer_contracts.unit_id')
            ->join('property_floors', 'property_floors.id', '=', 'units.property_floor_id');

        $query = $this->applyPropertyScopeToColumn($query, $scope, 'property_floors.property_id');

        if (!empty($filters['property_uuid'] ?? null)) {
            $query->join('properties as filter_properties', 'filter_properties.id', '=', 'property_floors.property_id')
                ->where('filter_properties.uuid', $filters['property_uuid']);
        }

        return $query;
    }

    /**
     * Resolve count for one status row.
     */
    private function statusRowCount(\Illuminate\Support\Collection $statusRows, string $status): int
    {
        return (int) optional($statusRows->firstWhere('status', $status))->contracts_count;
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
            'active_contract_amount' => $query->orderBy('revenue_collected', $direction)->orderBy('properties.name'),
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
     * Resolve the summary-cards reporting window.
     */
    private function resolveSummaryCardsWindow(array $filters): array
    {
        $range = (string) ($filters['range'] ?? '3_months');
        $today = now()->startOfDay();

        if ($range === 'custom') {
            return [
                Carbon::parse($filters['start_date'])->toDateString(),
                Carbon::parse($filters['end_date'])->toDateString(),
                $range,
            ];
        }

        $months = match ($range) {
            '6_months' => 6,
            '12_months' => 12,
            default => 3,
        };

        return [
            $today->copy()->subMonthsNoOverflow($months - 1)->startOfMonth()->toDateString(),
            $today->copy()->endOfMonth()->toDateString(),
            $range,
        ];
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
     * Transaction bucket expressions.
     */
    private function transactionBucketExpressions(string $period): array
    {
        $driver = DB::connection($this->tenantConnectionName())->getDriverName();

        if ($driver === 'pgsql') {
            return match ($period) {
                'day' => [
                    "TO_CHAR(customer_contract_transactions.transaction_date, 'YYYY-MM-DD')",
                    "TO_CHAR(customer_contract_transactions.transaction_date, 'DD Mon YYYY')",
                ],
                'year' => [
                    "TO_CHAR(customer_contract_transactions.transaction_date, 'YYYY')",
                    "TO_CHAR(customer_contract_transactions.transaction_date, 'YYYY')",
                ],
                default => [
                    "TO_CHAR(customer_contract_transactions.transaction_date, 'YYYY-MM')",
                    "TO_CHAR(customer_contract_transactions.transaction_date, 'Mon YYYY')",
                ],
            };
        }

        return match ($period) {
            'day' => [
                "DATE_FORMAT(customer_contract_transactions.transaction_date, '%Y-%m-%d')",
                "DATE_FORMAT(customer_contract_transactions.transaction_date, '%d %b %Y')",
            ],
            'year' => [
                "DATE_FORMAT(customer_contract_transactions.transaction_date, '%Y')",
                "DATE_FORMAT(customer_contract_transactions.transaction_date, '%Y')",
            ],
            default => [
                "DATE_FORMAT(customer_contract_transactions.transaction_date, '%Y-%m')",
                "DATE_FORMAT(customer_contract_transactions.transaction_date, '%b %Y')",
            ],
        };
    }

    /**
     * Monthly transaction bucket expressions aligned to YYYY-MM-01.
     */
    private function monthlyTransactionBucketExpressions(): array
    {
        $driver = DB::connection($this->tenantConnectionName())->getDriverName();

        if ($driver === 'pgsql') {
            return [
                "TO_CHAR(DATE_TRUNC('month', customer_contract_transactions.transaction_date), 'YYYY-MM-DD')",
                "TO_CHAR(DATE_TRUNC('month', customer_contract_transactions.transaction_date), 'Mon YYYY')",
            ];
        }

        return [
            "DATE_FORMAT(customer_contract_transactions.transaction_date, '%Y-%m-01')",
            "DATE_FORMAT(customer_contract_transactions.transaction_date, '%b %Y')",
        ];
    }

    /**
     * Apply property uuid filter to a contract query.
     */
    private function applyContractPropertyFilter(QueryBuilder $query, array $filters): QueryBuilder
    {
        if (!empty($filters['property_uuid'] ?? null)) {
            $query->join('properties as filter_properties', 'filter_properties.id', '=', 'property_floors.property_id')
                ->where('filter_properties.uuid', $filters['property_uuid']);
        }

        return $query;
    }

    /**
     * Apply property uuid filter to an expense query.
     */
    private function applyExpensePropertyFilter(QueryBuilder $query, array $filters): QueryBuilder
    {
        if (!empty($filters['property_uuid'] ?? null)) {
            $query->join('properties as filter_properties', 'filter_properties.id', '=', 'maintenance_jobs.property_id')
                ->where('filter_properties.uuid', $filters['property_uuid']);
        }

        return $query;
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
