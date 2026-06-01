<?php

namespace App\Http\Resources\App\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractPropertyReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'property_uuid' => $this->property_uuid,
            'property_name' => $this->property_name,
            'property_status' => $this->property_status,
            'contracts_count' => (int) $this->contracts_count,
            'active_contracts_count' => (int) $this->active_contracts_count,
            'expired_contracts_count' => (int) $this->expired_contracts_count,
            'terminated_contracts_count' => (int) $this->terminated_contracts_count,
            'renewed_contracts_count' => (int) $this->renewed_contracts_count,
            'draft_contracts_count' => (int) $this->draft_contracts_count,
            'total_contract_amount' => (float) $this->total_contract_amount,
            'active_contract_amount' => (float) $this->active_contract_amount,
            'latest_end_date' => $this->latest_end_date,
        ];
    }
}
