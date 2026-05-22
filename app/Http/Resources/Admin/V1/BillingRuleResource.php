<?php

namespace App\Http\Resources\Admin\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class BillingRuleResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'range_start' => $this->range_start,
            'range_end' => $this->range_end,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'sort_order' => $this->sort_order,
            'status' => $this->status,
            ...$this->timestamps(),
        ];
    }
}
