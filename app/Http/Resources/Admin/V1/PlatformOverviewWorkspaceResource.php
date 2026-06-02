<?php

namespace App\Http\Resources\Admin\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class PlatformOverviewWorkspaceResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'database' => $this->database,
            'status' => $this->status,
            'provisioning_status' => $this->provisioning_status,
            'created_at' => $this->formatTimestamp($this->created_at),
        ];
    }
}
