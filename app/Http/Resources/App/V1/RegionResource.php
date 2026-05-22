<?php

namespace App\Http\Resources\App\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'post_code' => $this->post_code,
            'status' => $this->status,
            'country' => $this->whenLoaded('country', fn () => [
                'uuid' => $this->country->uuid,
                'name' => $this->country->name,
                'code' => $this->country->code,
            ]),
            'districts_count' => $this->whenCounted('districts'),
        ];
    }
}
