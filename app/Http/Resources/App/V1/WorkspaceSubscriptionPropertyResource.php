<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class WorkspaceSubscriptionPropertyResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'property_uuid' => $this->uuid,
            'name' => $this->name,
            'status' => $this->status,
            'registered_units' => (int) $this->registered_units,
            'matched_rule' => $this->matched_rule,
            'estimated_price_cents' => (int) ($this->estimated_price_cents ?? 0),
            'created_at' => $this->formatTimestamp($this->created_at),
        ];
    }
}
