<?php

namespace App\Http\Resources\App\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractExpiringReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'contract_uuid' => $this->contract_uuid,
            'contract_number' => $this->contract_number,
            'status' => $this->status,
            'billing_cycle' => $this->billing_cycle,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'customer_uuid' => $this->customer_uuid,
            'customer_name' => $this->customer_name,
            'property_uuid' => $this->property_uuid,
            'property_name' => $this->property_name,
            'unit_uuid' => $this->unit_uuid,
            'unit_number' => $this->unit_number,
            'days_to_expiry' => $this->days_to_expiry !== null ? (int) $this->days_to_expiry : null,
        ];
    }
}
