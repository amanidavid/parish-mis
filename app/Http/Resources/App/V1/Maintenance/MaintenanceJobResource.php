<?php

namespace App\Http\Resources\App\V1\Maintenance;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class MaintenanceJobResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'reported_date' => $this->reported_date?->format('Y-m-d'),
            'expenses_count' => isset($this->expenses_count) ? (int) $this->expenses_count : null,
            'total_expense_amount' => isset($this->total_expense_amount) ? (float) $this->total_expense_amount : null,
            'property' => $this->whenLoaded('property', fn () => [
                'uuid' => $this->property->uuid,
                'name' => $this->property->name,
            ]),
            'property_floor' => $this->whenLoaded('propertyFloor', fn () => $this->propertyFloor ? [
                'uuid' => $this->propertyFloor->uuid,
                'name' => $this->propertyFloor->name,
                'floor_number' => $this->propertyFloor->floor_number,
            ] : null),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? [
                'uuid' => $this->unit->uuid,
                'unit_number' => $this->unit->unit_number,
                'status' => $this->unit->status,
            ] : null),
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy ? [
                'uuid' => $this->recordedBy->uuid,
                'name' => $this->recordedBy->name,
                'email' => $this->recordedBy->email,
            ] : null),
            'expenses' => MaintenanceExpenseResource::collection($this->whenLoaded('expenses')),
            ...$this->timestamps(),
        ];
    }
}
