<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class PropertyTypeResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'properties_count' => $this->whenCounted('properties'),
            ...$this->timestamps(),
        ];
    }
}
