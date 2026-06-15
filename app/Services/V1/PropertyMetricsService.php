<?php

namespace App\Services\V1;

use Illuminate\Support\Facades\DB;

class PropertyMetricsService
{
    private const CONTRACT_STATUSES = [
        'draft',
        'active',
        'expired',
        'terminated',
        'renewed',
    ];

    public function forProperty(int $propertyId): array
    {
        $row = DB::connection($this->tenantConnectionName())
            ->table('property_floors')
            ->leftJoin('units', 'units.property_floor_id', '=', 'property_floors.id')
            ->leftJoin('customer_contracts', 'customer_contracts.unit_id', '=', 'units.id')
            ->where('property_floors.property_id', $propertyId)
            ->selectRaw("COUNT(DISTINCT CASE WHEN units.status = 'occupied' THEN units.id END) as occupied_count")
            ->selectRaw("COUNT(DISTINCT CASE WHEN units.status = 'vacant' THEN units.id END) as vacant_count")
            ->selectRaw("COUNT(DISTINCT CASE WHEN units.status = 'maintenance' THEN units.id END) as maintenance_count")
            ->selectRaw('COUNT(customer_contracts.id) as contracts_count')
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'draft' THEN 1 ELSE 0 END) as draft_contracts_count")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'active' THEN 1 ELSE 0 END) as active_contracts_count")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'expired' THEN 1 ELSE 0 END) as expired_contracts_count")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'terminated' THEN 1 ELSE 0 END) as terminated_contracts_count")
            ->selectRaw("SUM(CASE WHEN customer_contracts.status = 'renewed' THEN 1 ELSE 0 END) as renewed_contracts_count")
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

    private function tenantConnectionName(): string
    {
        return (string) config('multitenancy.tenant_database_connection_name', 'tenant');
    }
}
