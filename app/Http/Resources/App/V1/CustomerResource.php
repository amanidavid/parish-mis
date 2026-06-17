<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class CustomerResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'property' => $this->whenLoaded('property', fn () => $this->property ? [
                'uuid' => $this->property->uuid,
                'name' => $this->property->name,
            ] : null),
            'customer_type' => $this->customer_type,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'notes' => $this->notes,
            'business_detail' => $this->whenLoaded('businessDetail', fn () => $this->businessDetail ? new CustomerBusinessDetailResource($this->businessDetail) : null),
            'contracts_count' => $this->whenCounted('contracts'),
            ...$this->timestamps(),
        ];
    }
}
