<?php

namespace App\Http\Resources\App\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'post_code' => $this->post_code,
            'status' => $this->status,
            'district' => $this->whenLoaded('district', fn () => [
                'uuid' => $this->district->uuid,
                'name' => $this->district->name,
                'region_uuid' => $this->district->region?->uuid,
            ]),
        ];
    }
}
