<?php

namespace App\Http\Resources\App\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractChartBucketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'bucket_key' => $this['bucket_key'],
            'bucket_label' => $this['bucket_label'],
            'contracts_count' => (int) ($this['contracts_count'] ?? 0),
            'gross_collected_amount' => (float) ($this['gross_collected_amount'] ?? 0),
            'refund_amount' => (float) ($this['refund_amount'] ?? 0),
            'revenue_collected' => (float) ($this['revenue_collected'] ?? 0),
        ];
    }
}
