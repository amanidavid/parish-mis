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
            'contracts_count' => (int) $this['contracts_count'],
            'recognized_contracts_count' => (int) $this['recognized_contracts_count'],
            'total_contract_amount' => (float) $this['total_contract_amount'],
            'recognized_contract_amount' => (float) $this['recognized_contract_amount'],
        ];
    }
}
