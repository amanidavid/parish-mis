<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class WorkspaceResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'status' => $this->status,
            'provisioning_status' => $this->provisioning_status,
            'is_ready' => $this->provisioning_status === 'ready',
            'provision_attempts' => $this->provision_attempts,
            'provision_error' => $this->provision_error,
            'provision_started_at' => $this->formatTimestamp($this->provision_started_at),
            'provisioned_at' => $this->formatTimestamp($this->provisioned_at),
            ...$this->timestamps(),
        ];
    }
}
