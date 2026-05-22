<?php

namespace App\Http\Resources\App\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistrictResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'post_code' => $this->post_code,
            'status' => $this->status,
            'region' => $this->whenLoaded('region', fn () => [
                'uuid' => $this->region->uuid,
                'name' => $this->region->name,
                'country_uuid' => $this->region->country?->uuid,
            ]),
            'wards_count' => $this->whenCounted('wards'),
        ];
    }
}
