<?php

namespace App\Services\V1;

use Illuminate\Support\Facades\DB;

class PropertyMetricsService
{
    private const UNIT_STATUS_OCCUPIED = 'occupied';
    private const UNIT_STATUS_VACANT = 'vacant';
    private const UNIT_STATUS_MAINTENANCE = 'maintenance';
    private const CONTRACT_STATUSES = [
        'draft',
        'active',
        'expired',
        'terminated',
    ];

    /**
     * Handle for property.
     */
    public function forProperty(int $propertyId): array
    {
        $connection = DB::connection($this->tenantConnectionName());

        $unitMetrics = $connection
            ->table('property_floors')
            ->leftJoin('units', 'units.property_floor_id', '=', 'property_floors.id')
            ->where('property_floors.property_id', $propertyId)
            ->selectRaw('COUNT(CASE WHEN units.status = ? THEN 1 END) as occupied_count', [self::UNIT_STATUS_OCCUPIED])
            ->selectRaw('COUNT(CASE WHEN units.status = ? THEN 1 END) as vacant_count', [self::UNIT_STATUS_VACANT])
            ->selectRaw('COUNT(CASE WHEN units.status = ? THEN 1 END) as maintenance_count', [self::UNIT_STATUS_MAINTENANCE]);

        $contractMetrics = $connection
            ->table('property_floors')
            ->join('units', 'units.property_floor_id', '=', 'property_floors.id')
            ->leftJoin('customer_contracts', 'customer_contracts.unit_id', '=', 'units.id')
            ->where('property_floors.property_id', $propertyId)
            ->selectRaw('COUNT(customer_contracts.id) as contracts_count')
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'draft' THEN 1 ELSE 0 END) as draft_contracts_count")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'active' THEN 1 ELSE 0 END) as active_contracts_count")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'expired' THEN 1 ELSE 0 END) as expired_contracts_count")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'terminated' THEN 1 ELSE 0 END) as terminated_contracts_count");

        $row = $connection
            ->query()
            ->fromSub($unitMetrics, 'unit_metrics')
            ->crossJoinSub($contractMetrics, 'contract_metrics')
            ->selectRaw('COALESCE(unit_metrics.occupied_count, 0) as occupied_count')
            ->selectRaw('COALESCE(unit_metrics.vacant_count, 0) as vacant_count')
            ->selectRaw('COALESCE(unit_metrics.maintenance_count, 0) as maintenance_count')
            ->selectRaw('COALESCE(contract_metrics.contracts_count, 0) as contracts_count')
            ->selectRaw('COALESCE(contract_metrics.draft_contracts_count, 0) as draft_contracts_count')
            ->selectRaw('COALESCE(contract_metrics.active_contracts_count, 0) as active_contracts_count')
            ->selectRaw('COALESCE(contract_metrics.expired_contracts_count, 0) as expired_contracts_count')
            ->selectRaw('COALESCE(contract_metrics.terminated_contracts_count, 0) as terminated_contracts_count')
            ->first();

        $contractStatuses = [];

        foreach (self::CONTRACT_STATUSES as $status) {
            $contractStatuses[$status] = (int) ($row->{$status.'_contracts_count'} ?? 0);
        }

        $contractStatuses['pending'] = $contractStatuses['draft'];

        return [
            'occupied_count' => (int) ($row->occupied_count ?? 0),
            'vacant_count' => (int) ($row->vacant_count ?? 0),
            'maintenance_count' => (int) ($row->maintenance_count ?? 0),
            'contracts_count' => (int) ($row->contracts_count ?? 0),
            'contract_statuses' => $contractStatuses,
        ];
    }

    /**
     * Tenant connection name.
     */
    private function tenantConnectionName(): string
    {
        return (string) config('multitenancy.tenant_database_connection_name', 'tenant');
    }
}
