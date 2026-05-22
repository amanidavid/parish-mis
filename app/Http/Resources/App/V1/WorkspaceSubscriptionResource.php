<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class WorkspaceSubscriptionResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'workspace_uuid' => $this['workspace_uuid'],
            'workspace_status' => $this['workspace_status'],
            'access_state' => $this['access_state'] ?? null,
            'access_message' => $this['access_message'] ?? null,
            'inventory_changes_allowed' => $this['inventory_changes_allowed'] ?? null,
            'subscription' => $this['subscription'],
            'usage' => $this['usage'],
        ];
    }
}
