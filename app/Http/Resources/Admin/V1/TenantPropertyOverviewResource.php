<?php

namespace App\Http\Resources\Admin\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class TenantPropertyOverviewResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'property_uuid' => $this->property_uuid,
            'name' => $this->name,
            'status' => $this->status,
            'country' => $this->country_uuid ? [
                'uuid' => $this->country_uuid,
                'name' => $this->country_name,
            ] : null,
            'region' => $this->region_uuid ? [
                'uuid' => $this->region_uuid,
                'name' => $this->region_name,
            ] : null,
            'district' => $this->district_uuid ? [
                'uuid' => $this->district_uuid,
                'name' => $this->district_name,
            ] : null,
            'ward' => $this->ward_uuid ? [
                'uuid' => $this->ward_uuid,
                'name' => $this->ward_name,
            ] : null,
            'floors_count' => (int) $this->floors_count,
            'units_count' => (int) $this->units_count,
            'occupied_units' => (int) $this->occupied_units,
            'vacant_units' => (int) $this->vacant_units,
            'maintenance_units' => (int) $this->maintenance_units,
            'customers_count' => (int) $this->customers_count,
            'contracts_count' => (int) $this->contracts_count,
            'active_contracts_count' => (int) $this->active_contracts_count,
            'created_at' => $this->formatTimestamp($this->created_at),
        ];
    }
}
