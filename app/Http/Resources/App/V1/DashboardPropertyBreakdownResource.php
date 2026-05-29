<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class DashboardPropertyBreakdownResource extends ApiJsonResource
{
    /** Present one property row with occupancy and contract health metrics for dashboard drill-down. */
    public function toArray(Request $request): array
    {
        $totalUnits = (int) ($this->total_units ?? 0);
        $occupiedUnits = (int) ($this->occupied_units ?? 0);
        $vacantUnits = (int) ($this->vacant_units ?? 0);

        return [
            'property_uuid' => $this->uuid,
            'property_name' => $this->name,
            'property_status' => $this->status,
            'total_units' => $totalUnits,
            'occupied_units' => $occupiedUnits,
            'vacant_units' => $vacantUnits,
            'maintenance_units' => (int) ($this->maintenance_units ?? 0),
            'occupancy_rate' => $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 2) : 0,
            'vacancy_rate' => $totalUnits > 0 ? round(($vacantUnits / $totalUnits) * 100, 2) : 0,
            'contracts' => [
                'total' => (int) ($this->contracts_total ?? 0),
                'draft' => (int) ($this->contracts_draft ?? 0),
                'active' => (int) ($this->contracts_active ?? 0),
                'expired' => (int) ($this->contracts_expired ?? 0),
                'terminated' => (int) ($this->contracts_terminated ?? 0),
                'renewed' => (int) ($this->contracts_renewed ?? 0),
            ],
        ];
    }
}
