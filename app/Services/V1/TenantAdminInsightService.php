<?php

namespace App\Services\V1;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TenantAdminInsightService
{
    private const MAX_PER_PAGE = 100;

    public function operationalSummary(): array
    {
        $propertyTotals = $this->tenantTable('properties')
            ->selectRaw('COUNT(id) as total_properties')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_properties")
            ->selectRaw("SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_properties")
            ->first();

        $floorTotals = $this->tenantTable('property_floors')
            ->selectRaw('COUNT(id) as total_floors')
            ->first();

        $unitTotals = $this->tenantTable('units')
            ->selectRaw('COUNT(id) as total_units')
            ->selectRaw("SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied_units")
            ->selectRaw("SUM(CASE WHEN status = 'vacant' THEN 1 ELSE 0 END) as vacant_units")
            ->selectRaw("SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_units")
            ->first();

        $customerTotals = $this->tenantTable('customers')
            ->selectRaw('COUNT(id) as total_customers')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_customers")
            ->selectRaw("SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_customers")
            ->first();

        $contractTotals = $this->tenantTable('customer_contracts')
            ->selectRaw('COUNT(id) as total_contracts')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_contracts")
            ->selectRaw("SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_contracts")
            ->selectRaw("SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_contracts")
            ->selectRaw("SUM(CASE WHEN status = 'terminated' THEN 1 ELSE 0 END) as terminated_contracts")
            ->selectRaw("SUM(CASE WHEN status = 'renewed' THEN 1 ELSE 0 END) as renewed_contracts")
            ->selectRaw('COALESCE(SUM(amount), 0) as total_contract_amount')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'active' THEN amount ELSE 0 END), 0) as active_contract_amount")
            ->first();

        $staffTotals = $this->tenantTable('users')
            ->selectRaw('COUNT(id) as total_staff')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_staff")
            ->selectRaw("SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_staff")
            ->first();

        return [
            'properties' => [
                'total' => (int) ($propertyTotals->total_properties ?? 0),
                'active' => (int) ($propertyTotals->active_properties ?? 0),
                'inactive' => (int) ($propertyTotals->inactive_properties ?? 0),
            ],
            'floors' => [
                'total' => (int) ($floorTotals->total_floors ?? 0),
            ],
            'units' => [
                'total' => (int) ($unitTotals->total_units ?? 0),
                'occupied' => (int) ($unitTotals->occupied_units ?? 0),
                'vacant' => (int) ($unitTotals->vacant_units ?? 0),
                'maintenance' => (int) ($unitTotals->maintenance_units ?? 0),
            ],
            'customers' => [
                'total' => (int) ($customerTotals->total_customers ?? 0),
                'active' => (int) ($customerTotals->active_customers ?? 0),
                'inactive' => (int) ($customerTotals->inactive_customers ?? 0),
            ],
            'contracts' => [
                'total' => (int) ($contractTotals->total_contracts ?? 0),
                'active' => (int) ($contractTotals->active_contracts ?? 0),
                'draft' => (int) ($contractTotals->draft_contracts ?? 0),
                'expired' => (int) ($contractTotals->expired_contracts ?? 0),
                'terminated' => (int) ($contractTotals->terminated_contracts ?? 0),
                'renewed' => (int) ($contractTotals->renewed_contracts ?? 0),
                'total_contract_amount' => (float) ($contractTotals->total_contract_amount ?? 0),
                'active_contract_amount' => (float) ($contractTotals->active_contract_amount ?? 0),
            ],
            'staff' => [
                'total' => (int) ($staffTotals->total_staff ?? 0),
                'active' => (int) ($staffTotals->active_staff ?? 0),
                'inactive' => (int) ($staffTotals->inactive_staff ?? 0),
            ],
        ];
    }

    public function propertyLocationSummary(array $filters = []): array
    {
        $propertiesCount = $this->propertiesLocationQuery($filters)->count('properties.id');

        $countryRows = $this->propertiesLocationQuery($filters)
            ->whereNotNull('properties.country_id')
            ->select([
                'countries.uuid as country_uuid',
                'countries.name as country_name',
            ])
            ->selectRaw('COUNT(properties.id) as properties_count')
            ->groupBy('countries.id', 'countries.uuid', 'countries.name')
            ->orderBy('countries.name')
            ->get();

        $regionRows = $this->propertiesLocationQuery($filters)
            ->whereNotNull('properties.region_id')
            ->select([
                'regions.uuid as region_uuid',
                'regions.name as region_name',
                'countries.uuid as country_uuid',
                'countries.name as country_name',
            ])
            ->selectRaw('COUNT(properties.id) as properties_count')
            ->groupBy('regions.id', 'regions.uuid', 'regions.name', 'countries.id', 'countries.uuid', 'countries.name')
            ->orderBy('countries.name')
            ->orderBy('regions.name')
            ->get();

        $districtRows = $this->propertiesLocationQuery($filters)
            ->whereNotNull('properties.district_id')
            ->select([
                'districts.uuid as district_uuid',
                'districts.name as district_name',
                'regions.uuid as region_uuid',
                'regions.name as region_name',
                'countries.uuid as country_uuid',
                'countries.name as country_name',
            ])
            ->selectRaw('COUNT(properties.id) as properties_count')
            ->groupBy(
                'districts.id',
                'districts.uuid',
                'districts.name',
                'regions.id',
                'regions.uuid',
                'regions.name',
                'countries.id',
                'countries.uuid',
                'countries.name'
            )
            ->orderBy('countries.name')
            ->orderBy('regions.name')
            ->orderBy('districts.name')
            ->get();

        $wardRows = $this->propertiesLocationQuery($filters)
            ->whereNotNull('properties.ward_id')
            ->select([
                'wards.uuid as ward_uuid',
                'wards.name as ward_name',
                'districts.uuid as district_uuid',
                'districts.name as district_name',
                'regions.uuid as region_uuid',
                'regions.name as region_name',
                'countries.uuid as country_uuid',
                'countries.name as country_name',
            ])
            ->selectRaw('COUNT(properties.id) as properties_count')
            ->groupBy(
                'wards.id',
                'wards.uuid',
                'wards.name',
                'districts.id',
                'districts.uuid',
                'districts.name',
                'regions.id',
                'regions.uuid',
                'regions.name',
                'countries.id',
                'countries.uuid',
                'countries.name'
            )
            ->orderBy('countries.name')
            ->orderBy('regions.name')
            ->orderBy('districts.name')
            ->orderBy('wards.name')
            ->get();

        return [
            'filters' => [
                'status' => $filters['status'] ?? null,
                'country_uuid' => $filters['country_uuid'] ?? null,
                'region_uuid' => $filters['region_uuid'] ?? null,
                'district_uuid' => $filters['district_uuid'] ?? null,
                'ward_uuid' => $filters['ward_uuid'] ?? null,
            ],
            'totals' => [
                'properties_count' => (int) $propertiesCount,
                'registered_countries_count' => $countryRows->count(),
                'registered_regions_count' => $regionRows->count(),
                'registered_districts_count' => $districtRows->count(),
                'registered_wards_count' => $wardRows->count(),
            ],
            'countries' => $countryRows->map(fn ($row) => [
                'country_uuid' => $row->country_uuid,
                'country_name' => $row->country_name,
                'properties_count' => (int) $row->properties_count,
            ])->values()->all(),
            'regions' => $regionRows->map(fn ($row) => [
                'region_uuid' => $row->region_uuid,
                'region_name' => $row->region_name,
                'country_uuid' => $row->country_uuid,
                'country_name' => $row->country_name,
                'properties_count' => (int) $row->properties_count,
            ])->values()->all(),
            'districts' => $districtRows->map(fn ($row) => [
                'district_uuid' => $row->district_uuid,
                'district_name' => $row->district_name,
                'region_uuid' => $row->region_uuid,
                'region_name' => $row->region_name,
                'country_uuid' => $row->country_uuid,
                'country_name' => $row->country_name,
                'properties_count' => (int) $row->properties_count,
            ])->values()->all(),
            'wards' => $wardRows->map(fn ($row) => [
                'ward_uuid' => $row->ward_uuid,
                'ward_name' => $row->ward_name,
                'district_uuid' => $row->district_uuid,
                'district_name' => $row->district_name,
                'region_uuid' => $row->region_uuid,
                'region_name' => $row->region_name,
                'country_uuid' => $row->country_uuid,
                'country_name' => $row->country_name,
                'properties_count' => (int) $row->properties_count,
            ])->values()->all(),
        ];
    }

    public function propertyLocationBreakdown(array $filters = []): array
    {
        $groupBy = (string) ($filters['group_by'] ?? 'country');
        $config = $this->propertyLocationBreakdownConfig($groupBy);
        $query = $this->propertiesLocationQuery($filters)
            ->whereNotNull($config['not_null_column']);

        if (!empty($filters['search'] ?? null)) {
            $query->where($config['search_column'], 'like', $filters['search'].'%');
        }

        $propertiesCount = (clone $query)->count('properties.id');

        $query
            ->select($config['select'])
            ->selectRaw('COUNT(properties.id) as properties_count')
            ->groupBy(...$config['group_by']);

        $this->applyPropertyLocationBreakdownSort($query, $config['name_column'], $filters['sort'] ?? null);

        $perPage = min((int) ($filters['per_page'] ?? 15), self::MAX_PER_PAGE);
        $rows = $query->paginate($perPage)->withQueryString();

        return [
            'group_by' => $groupBy,
            'filters' => [
                'status' => $filters['status'] ?? null,
                'country_uuid' => $filters['country_uuid'] ?? null,
                'region_uuid' => $filters['region_uuid'] ?? null,
                'district_uuid' => $filters['district_uuid'] ?? null,
                'ward_uuid' => $filters['ward_uuid'] ?? null,
                'search' => $filters['search'] ?? null,
                'sort' => $filters['sort'] ?? null,
            ],
            'totals' => [
                'registered_locations_count' => $rows->total(),
                'properties_count' => (int) $propertiesCount,
            ],
            'rows' => $rows,
        ];
    }

    public function propertyOverview(array $filters = []): LengthAwarePaginator
    {
        $floorTotals = $this->tenantTable('property_floors')
            ->select('property_id')
            ->selectRaw('COUNT(id) as floors_count')
            ->groupBy('property_id');

        $unitTotals = $this->tenantTable('property_floors')
            ->join('units', 'units.property_floor_id', '=', 'property_floors.id')
            ->select('property_floors.property_id')
            ->selectRaw('COUNT(units.id) as units_count')
            ->selectRaw("SUM(CASE WHEN units.status = 'occupied' THEN 1 ELSE 0 END) as occupied_units")
            ->selectRaw("SUM(CASE WHEN units.status = 'vacant' THEN 1 ELSE 0 END) as vacant_units")
            ->selectRaw("SUM(CASE WHEN units.status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_units")
            ->groupBy('property_floors.property_id');

        $contractTotals = $this->tenantTable('property_floors')
            ->join('units', 'units.property_floor_id', '=', 'property_floors.id')
            ->leftJoin('customer_contracts', 'customer_contracts.unit_id', '=', 'units.id')
            ->select('property_floors.property_id')
            ->selectRaw('COUNT(DISTINCT customer_contracts.id) as contracts_count')
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'active' THEN 1 ELSE 0 END) as active_contracts_count")
            ->selectRaw('COUNT(DISTINCT customer_contracts.customer_id) as customers_count')
            ->groupBy('property_floors.property_id');

        $query = $this->tenantTable('properties')
            ->leftJoin('countries', 'countries.id', '=', 'properties.country_id')
            ->leftJoin('regions', 'regions.id', '=', 'properties.region_id')
            ->leftJoin('districts', 'districts.id', '=', 'properties.district_id')
            ->leftJoin('wards', 'wards.id', '=', 'properties.ward_id')
            ->leftJoinSub($floorTotals, 'floor_totals', fn ($join) => $join->on('floor_totals.property_id', '=', 'properties.id'))
            ->leftJoinSub($unitTotals, 'unit_totals', fn ($join) => $join->on('unit_totals.property_id', '=', 'properties.id'))
            ->leftJoinSub($contractTotals, 'contract_totals', fn ($join) => $join->on('contract_totals.property_id', '=', 'properties.id'))
            ->select([
                'properties.uuid as property_uuid',
                'properties.name',
                'properties.status',
                'properties.created_at',
                'countries.uuid as country_uuid',
                'countries.name as country_name',
                'regions.uuid as region_uuid',
                'regions.name as region_name',
                'districts.uuid as district_uuid',
                'districts.name as district_name',
                'wards.uuid as ward_uuid',
                'wards.name as ward_name',
            ])
            ->selectRaw('COALESCE(floor_totals.floors_count, 0) as floors_count')
            ->selectRaw('COALESCE(unit_totals.units_count, 0) as units_count')
            ->selectRaw('COALESCE(unit_totals.occupied_units, 0) as occupied_units')
            ->selectRaw('COALESCE(unit_totals.vacant_units, 0) as vacant_units')
            ->selectRaw('COALESCE(unit_totals.maintenance_units, 0) as maintenance_units')
            ->selectRaw('COALESCE(contract_totals.customers_count, 0) as customers_count')
            ->selectRaw('COALESCE(contract_totals.contracts_count, 0) as contracts_count')
            ->selectRaw('COALESCE(contract_totals.active_contracts_count, 0) as active_contracts_count');

        if (!empty($filters['search'] ?? null)) {
            $query->where('properties.name', 'like', $filters['search'].'%');
        }

        if (!empty($filters['status'] ?? null)) {
            $query->where('properties.status', $filters['status']);
        }

        foreach (['country', 'region', 'district', 'ward'] as $segment) {
            $filterKey = $segment.'_uuid';
            if (!empty($filters[$filterKey] ?? null)) {
                $query->where($segment.'s.uuid', $filters[$filterKey]);
            }
        }

        $this->applyPropertyOverviewSort($query, $filters['sort'] ?? null);

        $perPage = min((int) ($filters['per_page'] ?? 15), self::MAX_PER_PAGE);

        return $query->paginate($perPage)->withQueryString();
    }

    public function contractsSummary(array $filters = []): array
    {
        $query = $this->tenantTable('customer_contracts')
            ->join('units', 'units.id', '=', 'customer_contracts.unit_id')
            ->join('property_floors', 'property_floors.id', '=', 'units.property_floor_id')
            ->join('properties', 'properties.id', '=', 'property_floors.property_id');

        if (!empty($filters['property_uuid'] ?? null)) {
            $query->where('properties.uuid', $filters['property_uuid']);
        }

        foreach (['country', 'region', 'district', 'ward'] as $segment) {
            $filterKey = $segment.'_uuid';
            if (!empty($filters[$filterKey] ?? null)) {
                $query->join($segment.'s', $segment.'s.id', '=', 'properties.'.$segment.'_id')
                    ->where($segment.'s.uuid', $filters[$filterKey]);
            }
        }

        if (!empty($filters['status'] ?? null)) {
            $query->where('customer_contracts.status', $filters['status']);
        }

        if (!empty($filters['billing_cycle'] ?? null)) {
            $query->where('customer_contracts.billing_cycle', $filters['billing_cycle']);
        }

        if (!empty($filters['start_date'] ?? null) || !empty($filters['end_date'] ?? null)) {
            $startDate = $filters['start_date'] ?? $filters['end_date'];
            $endDate = $filters['end_date'] ?? $filters['start_date'];

            $query->where('customer_contracts.start_date', '<=', $endDate)
                ->where(function (QueryBuilder $innerQuery) use ($startDate) {
                    $innerQuery
                        ->whereNull('customer_contracts.end_date')
                        ->orWhere('customer_contracts.end_date', '>=', $startDate);
                });
        }

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

        $expiringSoonCount = (clone $query)
            ->whereIn('customer_contracts.status', ['active', 'renewed'])
            ->whereNotNull('customer_contracts.end_date')
            ->whereBetween('customer_contracts.end_date', [
                Carbon::today()->toDateString(),
                Carbon::today()->addDays((int) ($filters['expiring_days'] ?? 30))->toDateString(),
            ])
            ->count('customer_contracts.id');

        return [
            'filters' => [
                'property_uuid' => $filters['property_uuid'] ?? null,
                'country_uuid' => $filters['country_uuid'] ?? null,
                'region_uuid' => $filters['region_uuid'] ?? null,
                'district_uuid' => $filters['district_uuid'] ?? null,
                'ward_uuid' => $filters['ward_uuid'] ?? null,
                'status' => $filters['status'] ?? null,
                'billing_cycle' => $filters['billing_cycle'] ?? null,
                'start_date' => $filters['start_date'] ?? null,
                'end_date' => $filters['end_date'] ?? null,
                'expiring_days' => (int) ($filters['expiring_days'] ?? 30),
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
                'expiring_soon_count' => (int) $expiringSoonCount,
            ],
            'by_status' => $statusBreakdown,
        ];
    }

    public function staffSummary(): array
    {
        $row = $this->tenantTable('users')
            ->selectRaw('COUNT(id) as total_staff')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_staff")
            ->selectRaw("SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_staff")
            ->first();

        return [
            'total_staff' => (int) ($row->total_staff ?? 0),
            'active_staff' => (int) ($row->active_staff ?? 0),
            'inactive_staff' => (int) ($row->inactive_staff ?? 0),
        ];
    }

    private function propertiesLocationQuery(array $filters = []): QueryBuilder
    {
        $query = $this->tenantTable('properties')
            ->leftJoin('countries', 'countries.id', '=', 'properties.country_id')
            ->leftJoin('regions', 'regions.id', '=', 'properties.region_id')
            ->leftJoin('districts', 'districts.id', '=', 'properties.district_id')
            ->leftJoin('wards', 'wards.id', '=', 'properties.ward_id');

        if (!empty($filters['status'] ?? null)) {
            $query->where('properties.status', $filters['status']);
        }

        foreach (['country', 'region', 'district', 'ward'] as $segment) {
            $filterKey = $segment.'_uuid';

            if (!empty($filters[$filterKey] ?? null)) {
                $query->where($segment.'s.uuid', $filters[$filterKey]);
            }
        }

        return $query;
    }

    private function applyPropertyOverviewSort(QueryBuilder $query, ?string $sort): void
    {
        $sort = trim((string) $sort);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        match ($key) {
            'status' => $query->orderBy('properties.status', $direction)->orderBy('properties.name'),
            'floors_count' => $query->orderBy('floors_count', $direction)->orderBy('properties.name'),
            'units_count' => $query->orderBy('units_count', $direction)->orderBy('properties.name'),
            'occupied_units' => $query->orderBy('occupied_units', $direction)->orderBy('properties.name'),
            'vacant_units' => $query->orderBy('vacant_units', $direction)->orderBy('properties.name'),
            'customers_count' => $query->orderBy('customers_count', $direction)->orderBy('properties.name'),
            'contracts_count' => $query->orderBy('contracts_count', $direction)->orderBy('properties.name'),
            'active_contracts_count' => $query->orderBy('active_contracts_count', $direction)->orderBy('properties.name'),
            'name', '' => $query->orderBy('properties.name', $direction),
            default => $query->orderBy('properties.name'),
        };
    }

    private function propertyLocationBreakdownConfig(string $groupBy): array
    {
        return match ($groupBy) {
            'region' => [
                'not_null_column' => 'properties.region_id',
                'search_column' => 'regions.name',
                'name_column' => 'regions.name',
                'group_by' => ['regions.id', 'regions.uuid', 'regions.name', 'countries.id', 'countries.uuid', 'countries.name'],
                'select' => [
                    'regions.uuid as group_uuid',
                    'regions.name as group_name',
                    'countries.uuid as country_uuid',
                    'countries.name as country_name',
                    'regions.uuid as region_uuid',
                    'regions.name as region_name',
                    DB::raw('NULL as district_uuid'),
                    DB::raw('NULL as district_name'),
                    DB::raw('NULL as ward_uuid'),
                    DB::raw('NULL as ward_name'),
                ],
            ],
            'district' => [
                'not_null_column' => 'properties.district_id',
                'search_column' => 'districts.name',
                'name_column' => 'districts.name',
                'group_by' => [
                    'districts.id',
                    'districts.uuid',
                    'districts.name',
                    'regions.id',
                    'regions.uuid',
                    'regions.name',
                    'countries.id',
                    'countries.uuid',
                    'countries.name',
                ],
                'select' => [
                    'districts.uuid as group_uuid',
                    'districts.name as group_name',
                    'countries.uuid as country_uuid',
                    'countries.name as country_name',
                    'regions.uuid as region_uuid',
                    'regions.name as region_name',
                    'districts.uuid as district_uuid',
                    'districts.name as district_name',
                    DB::raw('NULL as ward_uuid'),
                    DB::raw('NULL as ward_name'),
                ],
            ],
            'ward' => [
                'not_null_column' => 'properties.ward_id',
                'search_column' => 'wards.name',
                'name_column' => 'wards.name',
                'group_by' => [
                    'wards.id',
                    'wards.uuid',
                    'wards.name',
                    'districts.id',
                    'districts.uuid',
                    'districts.name',
                    'regions.id',
                    'regions.uuid',
                    'regions.name',
                    'countries.id',
                    'countries.uuid',
                    'countries.name',
                ],
                'select' => [
                    'wards.uuid as group_uuid',
                    'wards.name as group_name',
                    'countries.uuid as country_uuid',
                    'countries.name as country_name',
                    'regions.uuid as region_uuid',
                    'regions.name as region_name',
                    'districts.uuid as district_uuid',
                    'districts.name as district_name',
                    'wards.uuid as ward_uuid',
                    'wards.name as ward_name',
                ],
            ],
            default => [
                'not_null_column' => 'properties.country_id',
                'search_column' => 'countries.name',
                'name_column' => 'countries.name',
                'group_by' => ['countries.id', 'countries.uuid', 'countries.name'],
                'select' => [
                    'countries.uuid as group_uuid',
                    'countries.name as group_name',
                    'countries.uuid as country_uuid',
                    'countries.name as country_name',
                    DB::raw('NULL as region_uuid'),
                    DB::raw('NULL as region_name'),
                    DB::raw('NULL as district_uuid'),
                    DB::raw('NULL as district_name'),
                    DB::raw('NULL as ward_uuid'),
                    DB::raw('NULL as ward_name'),
                ],
            ],
        };
    }

    private function applyPropertyLocationBreakdownSort(QueryBuilder $query, string $nameColumn, ?string $sort): void
    {
        $sort = trim((string) $sort);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        match ($key) {
            'properties_count' => $query->orderBy('properties_count', $direction)->orderBy($nameColumn),
            'name', '' => $query->orderBy($nameColumn, $direction),
            default => $query->orderBy($nameColumn),
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
