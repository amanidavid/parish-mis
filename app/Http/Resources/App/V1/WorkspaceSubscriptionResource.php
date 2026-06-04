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
            'name' => $this['name'] ?? null,
            'display_name' => $this['display_name'] ?? null,
            'database' => $this['database'] ?? null,
            'workspace_status' => $this['workspace_status'],
            'created_at' => $this['created_at'] ?? null,
            'updated_at' => $this['updated_at'] ?? null,
            'access_state' => $this['access_state'] ?? null,
            'access_message' => $this['access_message'] ?? null,
            'inventory_changes_allowed' => $this['inventory_changes_allowed'] ?? null,
            'subscription' => $this['subscription'],
            'usage' => $this['usage'],
        ];
    }
}
