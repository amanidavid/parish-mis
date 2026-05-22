<?php

namespace App\Http\Resources\Admin\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class BillingProfileResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'billing_interval' => $this->billing_interval,
            'trial_days' => $this->trial_days,
            'grace_days' => $this->grace_days,
            'currency' => $this->currency,
            'is_default' => (bool) $this->is_default,
            'status' => $this->status,
            'rules_count' => $this->whenCounted('rules'),
            'rules' => BillingRuleResource::collection($this->whenLoaded('rules')),
            ...$this->timestamps(),
        ];
    }
}
