<?php

namespace App\Http\Resources\App\V1\Maintenance;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class MaintenanceExpensePropertyReportResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'property_uuid' => $this->property_uuid,
            'property_name' => $this->property_name,
            'property_status' => $this->property_status,
            'jobs_count' => (int) $this->jobs_count,
            'expenses_count' => (int) $this->expenses_count,
            'total_amount' => (float) $this->total_amount,
            'latest_expense_date' => $this->latest_expense_date,
        ];
    }
}
