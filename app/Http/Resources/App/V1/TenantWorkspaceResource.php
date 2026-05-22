<?php

namespace App\Http\Resources\App\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantWorkspaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'tenant_uuid' => $this->tenant_uuid ?? $this->uuid,
            'name' => $this->name ?? $this->display_name,
            'status' => $this->status ?? null,
            'provisioning_status' => $this->provisioning_status ?? null,
        ];
    }
}
