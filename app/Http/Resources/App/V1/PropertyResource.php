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
            'type' => $this->whenLoaded('type', fn () => [
                'uuid' => $this->type->uuid,
                'name' => $this->type->name,
            ]),
            'address_line' => $this->address_line,
            'postal_code' => $this->postal_code,
            'location' => $this->whenLoaded('ward', fn () => [
                'ward' => $this->ward ? [
                    'uuid' => $this->ward->uuid,
                    'name' => $this->ward->name,
                ] : null,
                'district' => $this->ward?->district ? [
                    'uuid' => $this->ward->district->uuid,
                    'name' => $this->ward->district->name,
                ] : null,
                'region' => $this->ward?->district?->region ? [
                    'uuid' => $this->ward->district->region->uuid,
                    'name' => $this->ward->district->region->name,
                ] : null,
                'country' => $this->ward?->district?->region?->country ? [
                    'uuid' => $this->ward->district->region->country->uuid,
                    'name' => $this->ward->district->region->country->name,
                    'code' => $this->ward->district->region->country->code,
                ] : null,
            ]),
            'status' => $this->status,
            'floors_count' => $this->whenCounted('floors'),
            'units_count' => $this->whenCounted('units'),
            ...$this->timestamps(),
        ];
    }
}
