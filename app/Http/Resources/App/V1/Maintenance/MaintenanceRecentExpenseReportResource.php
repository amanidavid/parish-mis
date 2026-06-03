<?php

namespace App\Http\Resources\App\V1\Maintenance;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class MaintenanceRecentExpenseReportResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'expense_uuid' => $this->expense_uuid,
            'title' => $this->expense_title,
            'description' => $this->expense_description,
            'amount' => (float) $this->amount,
            'expense_date' => $this->expense_date,
            'maintenance_job' => [
                'uuid' => $this->maintenance_job_uuid,
                'title' => $this->maintenance_job_title,
                'reported_date' => $this->reported_date,
            ],
            'property' => [
                'uuid' => $this->property_uuid,
                'name' => $this->property_name,
            ],
            'property_floor' => $this->property_floor_uuid ? [
                'uuid' => $this->property_floor_uuid,
                'name' => $this->property_floor_name,
                'floor_number' => $this->floor_number,
            ] : null,
            'unit' => $this->unit_uuid ? [
                'uuid' => $this->unit_uuid,
                'unit_number' => $this->unit_number,
            ] : null,
            'recorded_by' => $this->recorded_by_uuid ? [
                'uuid' => $this->recorded_by_uuid,
                'name' => $this->recorded_by_name,
                'email' => $this->recorded_by_email,
            ] : null,
            'created_at' => $this->formatTimestamp($this->created_at),
        ];
    }
}
