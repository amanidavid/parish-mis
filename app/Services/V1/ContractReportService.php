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

    public function __construct(
        private PropertyAssignmentAccessService $propertyAssignmentAccessService,
    ) {
    }

    public function summary(User $tenantUser, array $filters = []): array
    {
        $scope = $this->resolveScope($tenantUser);
        $query = $this->contractsBaseQuery($scope, $filters);

        $totals = (clone $query)
            ->selectRaw('COUNT(customer_contracts.id) as contracts_count')
            ->selectRaw('COALESCE(SUM(customer_contracts.amount), 0) as total_contract_amount')
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'active' THEN 1 ELSE 0 END) as active_contracts_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN customer_contracts.status = 'active' THEN customer_contracts.amount ELSE 0 END), 0) as active_contract_amount")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'draft' THEN 1 ELSE 0 END) as draft_contracts_count")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'expired' THEN 1 ELSE 0 END) as expired_contracts_count")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'terminated' THEN 1 ELSE 0 END) as terminated_contracts_count")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'renewed' THEN 1 ELSE 0 END) as renewed_contracts_count")
            ->first();

        $statusBreakdown = (clone $query)
            ->select('customer_contracts.status')
            ->selectRaw('COUNT(customer_contracts.id) as contracts_count')
            ->selectRaw('COALESCE(SUM(customer_contracts.amount), 0) as total_contract_amount')
            ->groupBy('customer_contracts.status')
            ->orderBy('customer_contracts.status')
            ->get()
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
                'billing_cycle' => $filters['billing_cycle'] ?? null,
                'start_date' => $filters['start_date'] ?? null,
                'end_date' => $filters['end_date'] ?? null,
            ],
            'totals' => [
                'contracts_count' => (int) ($totals->contracts_count ?? 0),
                'total_contract_amount' => (float) ($totals->total_contract_amount ?? 0),
                'active_contracts_count' => (int) ($totals->active_contracts_count ?? 0),
                'active_contract_amount' => (float) ($totals->active_contract_amount ?? 0),
                'draft_contracts_count' => (int) ($totals->draft_contracts_count ?? 0),
                'expired_contracts_count' => (int) ($totals->expired_contracts_count ?? 0),
                'terminated_contracts_count' => (int) ($totals->terminated_contracts_count ?? 0),
                'renewed_contracts_count' => (int) ($totals->renewed_contracts_count ?? 0),
            ],
            'by_status' => $statusBreakdown,
        ];
    }

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

    private function resolveScope(User $tenantUser): array
    {
        return [
            'bypass' => $this->propertyAssignmentAccessService->canBypassPropertyScope($tenantUser),
            'user_id' => (int) $tenantUser->id,
        ];
    }

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

    private function tenantTable(string $table): QueryBuilder
    {
        return DB::connection($this->tenantConnectionName())->table($table);
    }

    private function tenantConnectionName(): string
    {
        return (string) config('multitenancy.tenant_database_connection_name', 'tenant');
    }
}
