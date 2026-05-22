<?php

namespace App\Http\Resources\Admin\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class TenantResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'database' => $this->database,
            'status' => $this->status,
            'provisioning_status' => $this->provisioning_status,
            'is_ready' => $this->provisioning_status === 'ready',
            'provision_attempts' => $this->provision_attempts,
            'provision_error' => $this->provision_error,
            'provision_started_at' => $this->formatTimestamp($this->provision_started_at),
            'provisioned_at' => $this->formatTimestamp($this->provisioned_at),
            'meta' => $this->meta,
            ...$this->timestamps(),
        ];
    }
}
