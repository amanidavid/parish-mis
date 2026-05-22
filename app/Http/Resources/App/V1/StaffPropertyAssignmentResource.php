<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class StaffPropertyAssignmentResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'user' => $this->whenLoaded('user', fn () => [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
                'status' => $this->user->status,
            ]),
            'property' => $this->whenLoaded('property', fn () => [
                'uuid' => $this->property->uuid,
                'name' => $this->property->name,
            ]),
            ...$this->timestamps(),
        ];
    }
}
