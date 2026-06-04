<?php

namespace App\Http\Resources\Admin\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class SubscriptionUsageAdjustmentPreviewResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'workspace_uuid' => data_get($this->resource, 'workspace_uuid'),
            'subscription_uuid' => data_get($this->resource, 'subscription_uuid'),
            'subscription_status' => data_get($this->resource, 'subscription_status'),
            'billing_profile' => data_get($this->resource, 'billing_profile'),
            'eligibility' => data_get($this->resource, 'eligibility'),
            'effective_at' => data_get($this->resource, 'effective_at'),
            'period_starts_at' => data_get($this->resource, 'period_starts_at'),
            'period_ends_at' => data_get($this->resource, 'period_ends_at'),
            'baseline' => data_get($this->resource, 'baseline'),
            'current' => data_get($this->resource, 'current'),
            'pricing' => data_get($this->resource, 'pricing'),
            'proration' => data_get($this->resource, 'proration'),
            'pending_adjustment' => data_get($this->resource, 'pending_adjustment'),
        ];
    }
}
