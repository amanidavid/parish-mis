<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class PropertyResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        $country = $this->country ?? $this->district?->region?->country;
        $region = $this->region ?? $this->district?->region;

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'type' => $this->whenLoaded('type', fn () => $this->type ? [
                'uuid' => $this->type->uuid,
                'name' => $this->type->name,
            ] : null),
            'address_line' => $this->address_line,
            'postal_code' => $this->postal_code,
            'location' => [
                'country' => $country ? [
                    'uuid' => $country->uuid,
                    'name' => $country->name,
                    'code' => $country->code,
                ] : null,
                'region' => $region ? [
                    'uuid' => $region->uuid,
                    'name' => $region->name,
                ] : null,
                'district' => $this->district ? [
                    'uuid' => $this->district->uuid,
                    'name' => $this->district->name,
                ] : null,
                'ward' => $this->ward ? [
                    'uuid' => $this->ward->uuid,
                    'name' => $this->ward->name,
                ] : null,
            ],
            'status' => $this->status,
            'floors_count' => $this->whenCounted('floors'),
            'units_count' => $this->whenCounted('units'),
            ...$this->timestamps(),
        ];
    }
}
