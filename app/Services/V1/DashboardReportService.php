<?php

namespace App\Services\V1;

use App\Models\Tenant\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class DashboardReportService
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private PropertyAssignmentAccessService $propertyAssignmentAccessService,
    )
    {
    }

    /** Build the tenant dashboard dataset with compact summary cards and paginated property analytics. */
    public function overview(User $tenantUser, array $filters = []): array
    {
        $scope = $this->resolveScope($tenantUser);

        return [
            'summary' => $this->summary($scope),
            'property_breakdown' => $this->propertyBreakdown($filters, $scope),
        ];
    }

    /** Return the top-level dashboard counts using grouped queries instead of repeated row-by-row loops. */
    private function summary(array $scope): array
    {
        $totalProperties = $this->propertiesBaseQuery($scope)->count();

        $unitSummary = $this->unitsBaseQuery($scope)
            ->selectRaw('COUNT(*) as total_units')
            ->selectRaw("SUM(CASE WHEN units.status = 'occupied' THEN 1 ELSE 0 END) as occupied_units")
            ->selectRaw("SUM(CASE WHEN units.status = 'vacant' THEN 1 ELSE 0 END) as vacant_units")
            ->first();

        $contractSummary = $this->contractsBaseQuery($scope)
            ->selectRaw('COUNT(*) as total_contracts')
            ->selectRaw('COUNT(DISTINCT customer_contracts.customer_id) as total_customers')
            ->first();

        return [
            'total_properties' => $totalProperties,
            'total_units' => (int) ($unitSummary->total_units ?? 0),
            'occupied_units' => (int) ($unitSummary->occupied_units ?? 0),
            'vacant_units' => (int) ($unitSummary->vacant_units ?? 0),
            'total_customers' => (int) ($contractSummary->total_customers ?? 0),
            'total_staff' => User::query()->count(),
            'total_contracts' => (int) ($contractSummary->total_contracts ?? 0),
        ];
    }

    /** Return the property table shown under the cards using aggregated subqueries and server-side pagination. */
    private function propertyBreakdown(array $filters, array $scope): LengthAwarePaginator
    {
        $query = $this->propertiesBaseQuery($scope)
            ->select([
                'properties.id',
                'properties.uuid',
                'properties.name',
                'properties.status',
            ])
            ->leftJoinSub($this->unitAggregateSubquery($scope), 'unit_metrics', function ($join) {
                $join->on('unit_metrics.property_id', '=', 'properties.id');
            })
            ->leftJoinSub($this->contractAggregateSubquery($scope), 'contract_metrics', function ($join) {
                $join->on('contract_metrics.property_id', '=', 'properties.id');
            })
            ->addSelect([
                DB::raw('COALESCE(unit_metrics.total_units, 0) as total_units'),
                DB::raw('COALESCE(unit_metrics.occupied_units, 0) as occupied_units'),
                DB::raw('COALESCE(unit_metrics.vacant_units, 0) as vacant_units'),
                DB::raw('COALESCE(unit_metrics.maintenance_units, 0) as maintenance_units'),
                DB::raw('COALESCE(contract_metrics.contracts_total, 0) as contracts_total'),
                DB::raw('COALESCE(contract_metrics.contracts_draft, 0) as contracts_draft'),
                DB::raw('COALESCE(contract_metrics.contracts_active, 0) as contracts_active'),
                DB::raw('COALESCE(contract_metrics.contracts_expired, 0) as contracts_expired'),
                DB::raw('COALESCE(contract_metrics.contracts_terminated, 0) as contracts_terminated'),
                DB::raw('COALESCE(contract_metrics.contracts_renewed, 0) as contracts_renewed'),
            ]);

        if (!empty($filters['search'] ?? null)) {
            $query->where('properties.name', 'like', $filters['search'].'%');
        }

        if (!empty($filters['property_status'] ?? null)) {
            $query->where('properties.status', $filters['property_status']);
        }

        $this->applyBreakdownSort($query, $filters['sort'] ?? null);

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), self::MAX_PER_PAGE);

        return $query
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Aggregate units by property so occupancy cards and table rows come from one indexed pass. */
    private function unitAggregateSubquery(array $scope): QueryBuilder
    {
        $query = DB::table('property_floors')
            ->select('property_floors.property_id')
            ->leftJoin('units', 'units.property_floor_id', '=', 'property_floors.id')
            ->groupBy('property_floors.property_id')
            ->selectRaw('COUNT(units.id) as total_units')
            ->selectRaw("SUM(CASE WHEN units.status = 'occupied' THEN 1 ELSE 0 END) as occupied_units")
            ->selectRaw("SUM(CASE WHEN units.status = 'vacant' THEN 1 ELSE 0 END) as vacant_units")
            ->selectRaw("SUM(CASE WHEN units.status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_units");

        return $this->applyPropertyScopeToColumn($query, $scope, 'property_floors.property_id');
    }

    /** Aggregate contract statuses by property so the dashboard can compare contract health side by side. */
    private function contractAggregateSubquery(array $scope): QueryBuilder
    {
        $query = DB::table('property_floors')
            ->select('property_floors.property_id')
            ->join('units', 'units.property_floor_id', '=', 'property_floors.id')
            ->leftJoin('customer_contracts', 'customer_contracts.unit_id', '=', 'units.id')
            ->groupBy('property_floors.property_id')
            ->selectRaw('COUNT(customer_contracts.id) as contracts_total')
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'draft' THEN 1 ELSE 0 END) as contracts_draft")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'active' THEN 1 ELSE 0 END) as contracts_active")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'expired' THEN 1 ELSE 0 END) as contracts_expired")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'terminated' THEN 1 ELSE 0 END) as contracts_terminated")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'renewed' THEN 1 ELSE 0 END) as contracts_renewed");

        return $this->applyPropertyScopeToColumn($query, $scope, 'property_floors.property_id');
    }

    /** Build the base property query and apply staff-assignment scope only once. */
    private function propertiesBaseQuery(array $scope): QueryBuilder
    {
        return $this->applyPropertyScopeToColumn(DB::table('properties'), $scope, 'properties.id');
    }

    /** Build the base units query so summary counts can reuse the same scope and joins. */
    private function unitsBaseQuery(array $scope): QueryBuilder
    {
        $query = DB::table('units')
            ->join('property_floors', 'property_floors.id', '=', 'units.property_floor_id');

        return $this->applyPropertyScopeToColumn($query, $scope, 'property_floors.property_id');
    }

    /** Build the base contracts query so customer and contract counts stay aligned to the same property scope. */
    private function contractsBaseQuery(array $scope): QueryBuilder
    {
        $query = DB::table('customer_contracts')
            ->join('units', 'units.id', '=', 'customer_contracts.unit_id')
            ->join('property_floors', 'property_floors.id', '=', 'units.property_floor_id');

        return $this->applyPropertyScopeToColumn($query, $scope, 'property_floors.property_id');
    }

    /** Resolve dashboard scope once so every downstream query shares the same property-access rules. */
    private function resolveScope(User $tenantUser): array
    {
        return [
            'bypass' => $this->propertyAssignmentAccessService->canBypassPropertyScope($tenantUser),
            'user_id' => (int) $tenantUser->id,
        ];
    }

    /** Keep sorting explicit and index-friendly for the property table. */
    private function applyBreakdownSort(QueryBuilder $query, ?string $sort): void
    {
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $column = ltrim((string) $sort, '-');

        match ($column) {
            'total_units' => $query->orderBy('total_units', $direction)->orderBy('properties.name'),
            'occupied_units' => $query->orderBy('occupied_units', $direction)->orderBy('properties.name'),
            'vacant_units' => $query->orderBy('vacant_units', $direction)->orderBy('properties.name'),
            'contracts_total' => $query->orderBy('contracts_total', $direction)->orderBy('properties.name'),
            'contracts_active' => $query->orderBy('contracts_active', $direction)->orderBy('properties.name'),
            'property_status' => $query->orderBy('properties.status', $direction)->orderBy('properties.name'),
            'name', '' => $query->orderBy('properties.name', $direction),
            default => $query->orderBy('properties.name'),
        };
    }

    /** Keep staff property scope inside SQL so the database can use assignment indexes directly. */
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

}
