<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class UnitResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'unit_number' => $this->unit_number,
            'description' => $this->description,
            'status' => $this->status,
            'property_floor' => $this->whenLoaded('propertyFloor', fn () => [
                'uuid' => $this->propertyFloor->uuid,
                'name' => $this->propertyFloor->name,
                'floor_number' => $this->propertyFloor->floor_number,
            ]),
            'property' => $this->whenLoaded('propertyFloor', fn () => $this->propertyFloor?->property ? [
                'uuid' => $this->propertyFloor->property->uuid,
                'name' => $this->propertyFloor->property->name,
            ] : null),
            ...$this->timestamps(),
        ];
    }
}
