<?php

namespace App\Services\V1\Maintenance;

use App\Models\Tenant\User;
use App\Services\V1\PropertyAssignmentAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MaintenanceReportService
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private PropertyAssignmentAccessService $propertyAssignmentAccessService)
    {
    }

    public function summary(User $tenantUser, array $filters = []): array
    {
        $scope = $this->resolveScope($tenantUser);
        $query = $this->expensesBaseQuery($scope, $filters);

        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $yearStart = now()->startOfYear()->toDateString();
        $yearEnd = now()->endOfYear()->toDateString();

        $totals = (clone $query)
            ->selectRaw('COUNT(maintenance_expenses.id) as expenses_count')
            ->selectRaw('COUNT(DISTINCT maintenance_jobs.id) as jobs_count')
            ->selectRaw('COALESCE(SUM(maintenance_expenses.amount), 0) as total_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN maintenance_expenses.expense_date = ? THEN maintenance_expenses.amount ELSE 0 END), 0) as today_amount', [$today])
            ->selectRaw('COALESCE(SUM(CASE WHEN maintenance_expenses.expense_date BETWEEN ? AND ? THEN maintenance_expenses.amount ELSE 0 END), 0) as this_month_amount', [$monthStart, $monthEnd])
            ->selectRaw('COALESCE(SUM(CASE WHEN maintenance_expenses.expense_date BETWEEN ? AND ? THEN maintenance_expenses.amount ELSE 0 END), 0) as this_year_amount', [$yearStart, $yearEnd])
            ->first();

        return [
            'filters' => [
                'property_uuid' => $filters['property_uuid'] ?? null,
                'property_floor_uuid' => $filters['property_floor_uuid'] ?? null,
                'unit_uuid' => $filters['unit_uuid'] ?? null,
                'maintenance_job_uuid' => $filters['maintenance_job_uuid'] ?? null,
                'start_date' => $filters['start_date'] ?? null,
                'end_date' => $filters['end_date'] ?? null,
            ],
            'totals' => [
                'jobs_count' => (int) ($totals->jobs_count ?? 0),
                'expenses_count' => (int) ($totals->expenses_count ?? 0),
                'total_amount' => (float) ($totals->total_amount ?? 0),
                'today_amount' => (float) ($totals->today_amount ?? 0),
                'this_month_amount' => (float) ($totals->this_month_amount ?? 0),
                'this_year_amount' => (float) ($totals->this_year_amount ?? 0),
            ],
        ];
    }

    public function byProperty(User $tenantUser, array $filters = []): LengthAwarePaginator
    {
        $scope = $this->resolveScope($tenantUser);
        $query = $this->expensesBaseQuery($scope, $filters)
            ->join('properties', 'properties.id', '=', 'maintenance_jobs.property_id')
            ->select([
                'properties.uuid as property_uuid',
                'properties.name as property_name',
                'properties.status as property_status',
            ])
            ->selectRaw('COUNT(DISTINCT maintenance_jobs.id) as jobs_count')
            ->selectRaw('COUNT(maintenance_expenses.id) as expenses_count')
            ->selectRaw('COALESCE(SUM(maintenance_expenses.amount), 0) as total_amount')
            ->selectRaw('MAX(maintenance_expenses.expense_date) as latest_expense_date')
            ->groupBy('properties.id', 'properties.uuid', 'properties.name', 'properties.status');

        if (!empty($filters['search'] ?? null)) {
            $query->where('properties.name', 'like', $filters['search'].'%');
        }

        $this->applyByPropertySort($query, $filters['sort'] ?? null);

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), self::MAX_PER_PAGE);

        return $query->paginate($perPage)->withQueryString();
    }

    public function recentExpenses(User $tenantUser, array $filters = []): LengthAwarePaginator
    {
        $scope = $this->resolveScope($tenantUser);
        $query = $this->expensesBaseQuery($scope, $filters)
            ->join('properties', 'properties.id', '=', 'maintenance_jobs.property_id')
            ->leftJoin('property_floors', 'property_floors.id', '=', 'maintenance_jobs.property_floor_id')
            ->leftJoin('units', 'units.id', '=', 'maintenance_jobs.unit_id')
            ->leftJoin('users as expense_recorders', 'expense_recorders.id', '=', 'maintenance_expenses.recorded_by')
            ->select([
                'maintenance_expenses.uuid as expense_uuid',
                'maintenance_expenses.title as expense_title',
                'maintenance_expenses.description as expense_description',
                'maintenance_expenses.amount',
                'maintenance_expenses.expense_date',
                'maintenance_expenses.created_at',
                'maintenance_jobs.uuid as maintenance_job_uuid',
                'maintenance_jobs.title as maintenance_job_title',
                'maintenance_jobs.reported_date',
                'properties.uuid as property_uuid',
                'properties.name as property_name',
                'property_floors.uuid as property_floor_uuid',
                'property_floors.name as property_floor_name',
                'property_floors.floor_number',
                'units.uuid as unit_uuid',
                'units.unit_number',
                'expense_recorders.uuid as recorded_by_uuid',
                'expense_recorders.name as recorded_by_name',
                'expense_recorders.email as recorded_by_email',
            ]);

        if (!empty($filters['search'] ?? null)) {
            $query->where(function (QueryBuilder $innerQuery) use ($filters) {
                $innerQuery
                    ->where('maintenance_expenses.title', 'like', $filters['search'].'%')
                    ->orWhere('maintenance_jobs.title', 'like', $filters['search'].'%')
                    ->orWhere('properties.name', 'like', $filters['search'].'%');
            });
        }

        $this->applyRecentSort($query, $filters['sort'] ?? null);

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), self::MAX_PER_PAGE);

        return $query->paginate($perPage)->withQueryString();
    }

    private function expensesBaseQuery(array $scope, array $filters, bool $applyDateWindow = true): QueryBuilder
    {
        $query = $this->tenantTable('maintenance_expenses')
            ->join('maintenance_jobs', 'maintenance_jobs.id', '=', 'maintenance_expenses.maintenance_job_id');

        $query = $this->applyPropertyScopeToColumn($query, $scope, 'maintenance_jobs.property_id');

        if (!empty($filters['maintenance_job_uuid'] ?? null)) {
            $query->where('maintenance_jobs.uuid', $filters['maintenance_job_uuid']);
        }

        if (!empty($filters['property_uuid'] ?? null)) {
            $query->join('properties as filter_properties', 'filter_properties.id', '=', 'maintenance_jobs.property_id')
                ->where('filter_properties.uuid', $filters['property_uuid']);
        }

        if (!empty($filters['property_floor_uuid'] ?? null)) {
            $query->join('property_floors as filter_property_floors', 'filter_property_floors.id', '=', 'maintenance_jobs.property_floor_id')
                ->where('filter_property_floors.uuid', $filters['property_floor_uuid']);
        }

        if (!empty($filters['unit_uuid'] ?? null)) {
            $query->join('units as filter_units', 'filter_units.id', '=', 'maintenance_jobs.unit_id')
                ->where('filter_units.uuid', $filters['unit_uuid']);
        }

        if ($applyDateWindow && (!empty($filters['start_date'] ?? null) || !empty($filters['end_date'] ?? null))) {
            $startDate = !empty($filters['start_date'] ?? null)
                ? Carbon::parse($filters['start_date'])->toDateString()
                : Carbon::parse($filters['end_date'])->toDateString();
            $endDate = !empty($filters['end_date'] ?? null)
                ? Carbon::parse($filters['end_date'])->toDateString()
                : Carbon::parse($filters['start_date'])->toDateString();

            $query->whereBetween('maintenance_expenses.expense_date', [$startDate, $endDate]);
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

    private function applyByPropertySort(QueryBuilder $query, ?string $sort): void
    {
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $column = ltrim((string) $sort, '-');

        match ($column) {
            'jobs_count' => $query->orderBy('jobs_count', $direction)->orderBy('properties.name'),
            'expenses_count' => $query->orderBy('expenses_count', $direction)->orderBy('properties.name'),
            'total_amount' => $query->orderBy('total_amount', $direction)->orderBy('properties.name'),
            'latest_expense_date' => $query->orderBy('latest_expense_date', $direction)->orderBy('properties.name'),
            'name', '' => $query->orderBy('properties.name', $direction),
            default => $query->orderBy('properties.name'),
        };
    }

    private function applyRecentSort(QueryBuilder $query, ?string $sort): void
    {
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $column = ltrim((string) $sort, '-');

        match ($column) {
            'amount' => $query->orderBy('maintenance_expenses.amount', $direction)->orderBy('maintenance_expenses.expense_date', 'desc'),
            'title' => $query->orderBy('maintenance_expenses.title', $direction)->orderBy('maintenance_expenses.expense_date', 'desc'),
            'property_name' => $query->orderBy('properties.name', $direction)->orderBy('maintenance_expenses.expense_date', 'desc'),
            'created_at' => $query->orderBy('maintenance_expenses.created_at', $direction)->orderBy('maintenance_expenses.id', 'desc'),
            'expense_date', '' => $query->orderBy('maintenance_expenses.expense_date', $direction)->orderBy('maintenance_expenses.id', 'desc'),
            default => $query->orderBy('maintenance_expenses.expense_date', 'desc')->orderBy('maintenance_expenses.id', 'desc'),
        };
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
