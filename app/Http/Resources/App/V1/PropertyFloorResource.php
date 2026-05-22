<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class PropertyFloorResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'floor_number' => $this->floor_number,
            'property' => $this->whenLoaded('property', fn () => [
                'uuid' => $this->property->uuid,
                'name' => $this->property->name,
            ]),
            'units_count' => $this->whenCounted('units'),
            ...$this->timestamps(),
        ];
    }
}
