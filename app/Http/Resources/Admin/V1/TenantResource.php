<?php

namespace App\Http\Resources\Admin\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class TenantResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        $canRetryProvisioning = in_array($this->provisioning_status, ['failed', 'pending'], true);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'database' => $this->database,
            'status' => $this->status,
            'provisioning_status' => $this->provisioning_status,
            'is_ready' => $this->provisioning_status === 'ready',
            'is_provisioned' => $this->provisioned_at !== null,
            'can_retry_provisioning' => $canRetryProvisioning,
            'retry_provisioning_reason' => $canRetryProvisioning
                ? null
                : ($this->provisioning_status === 'provisioning'
                    ? 'Provisioning is already in progress.'
                    : ($this->provisioning_status === 'ready'
                        ? 'This workspace is already provisioned and ready.'
                        : 'Retry provisioning is not available for the current workspace state.')),
            'provision_attempts' => $this->provision_attempts,
            'provision_error' => $this->provision_error,
            'provision_started_at' => $this->formatTimestamp($this->provision_started_at),
            'provisioned_at' => $this->formatTimestamp($this->provisioned_at),
            'meta' => $this->meta,
            ...$this->timestamps(),
        ];
    }
}
