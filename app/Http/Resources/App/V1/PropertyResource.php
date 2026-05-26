<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class PropertyResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'type' => $this->whenLoaded('type', fn () => $this->type ? [
                'uuid' => $this->type->uuid,
                'name' => $this->type->name,
            ] : null),
            'address_line' => $this->address_line,
            'postal_code' => $this->postal_code,
            'location' => $this->whenLoaded('district', fn () => [
                'district' => $this->district ? [
                    'uuid' => $this->district->uuid,
                    'name' => $this->district->name,
                ] : null,
                'region' => $this->district?->region ? [
                    'uuid' => $this->district->region->uuid,
                    'name' => $this->district->region->name,
                ] : null,
                'country' => $this->district?->region?->country ? [
                    'uuid' => $this->district->region->country->uuid,
                    'name' => $this->district->region->country->name,
                    'code' => $this->district->region->country->code,
                ] : null,
            ]),
            'status' => $this->status,
            'floors_count' => $this->whenCounted('floors'),
            'units_count' => $this->whenCounted('units'),
            ...$this->timestamps(),
        ];
    }
}
