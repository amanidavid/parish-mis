<?php

namespace App\Http\Resources\App\V1\Maintenance;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class MaintenanceExpenseResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        $maintenanceJob = $this->relationLoaded('maintenanceJob') ? $this->maintenanceJob : null;

        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'amount' => (float) $this->amount,
            'expense_date' => $this->expense_date?->format('Y-m-d'),
            'maintenance_job' => $this->whenLoaded('maintenanceJob', fn () => $this->maintenanceJob ? [
                'uuid' => $this->maintenanceJob->uuid,
                'title' => $this->maintenanceJob->title,
                'status' => $this->maintenanceJob->status,
                'reported_date' => $this->maintenanceJob->reported_date?->format('Y-m-d'),
            ] : null),
            'property' => $this->whenLoaded('maintenanceJob', fn () => $maintenanceJob?->property ? [
                'uuid' => $maintenanceJob->property->uuid,
                'name' => $maintenanceJob->property->name,
            ] : null),
            'property_floor' => $this->whenLoaded('maintenanceJob', fn () => $maintenanceJob?->propertyFloor ? [
                'uuid' => $maintenanceJob->propertyFloor->uuid,
                'name' => $maintenanceJob->propertyFloor->name,
                'floor_number' => $maintenanceJob->propertyFloor->floor_number,
            ] : null),
            'unit' => $this->whenLoaded('maintenanceJob', fn () => $maintenanceJob?->unit ? [
                'uuid' => $maintenanceJob->unit->uuid,
                'unit_number' => $maintenanceJob->unit->unit_number,
                'status' => $maintenanceJob->unit->status,
            ] : null),
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy ? [
                'uuid' => $this->recordedBy->uuid,
                'name' => $this->recordedBy->name,
                'email' => $this->recordedBy->email,
            ] : null),
            ...$this->timestamps(),
        ];
    }
}
