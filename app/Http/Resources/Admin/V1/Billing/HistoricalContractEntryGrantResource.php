<?php

namespace App\Http\Resources\Admin\V1\Billing;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class HistoricalContractEntryGrantResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        $workspaceProperty = $this->workspaceProperty;

        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'effective_status' => $this->effectiveStatus(),
            'scope' => $workspaceProperty ? 'property' : 'workspace',
            'historical_start_date_from' => $this->historical_start_date_from?->toDateString(),
            'historical_start_date_to' => $this->historical_start_date_to?->toDateString(),
            'usable_from' => $this->formatTimestamp($this->usable_from),
            'expires_at' => $this->formatTimestamp($this->expires_at),
            'reason' => $this->reason,
            'revocation_reason' => $this->revocation_reason,
            'approved_at' => $this->formatTimestamp($this->approved_at),
            'revoked_at' => $this->formatTimestamp($this->revoked_at),
            'property' => $workspaceProperty ? [
                'uuid' => $workspaceProperty->property_uuid,
                'name' => $workspaceProperty->property_name,
                'is_deleted' => $workspaceProperty->property_deleted_at !== null,
            ] : null,
            'approved_by' => $this->approvedBy ? [
                'uuid' => $this->approvedBy->uuid,
                'name' => $this->approvedBy->name,
            ] : null,
            'revoked_by' => $this->revokedBy ? [
                'uuid' => $this->revokedBy->uuid,
                'name' => $this->revokedBy->name,
            ] : null,
            'meta' => $this->meta,
            ...$this->timestamps(),
        ];
    }
}
