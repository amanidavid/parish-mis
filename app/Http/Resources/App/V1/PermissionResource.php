<?php

namespace App\Http\Resources\App\V1;

use App\Support\PermissionLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $name = (string) $this->name;
        $module = (string) ($this->module ?? PermissionLabel::moduleFromName($name));

        return [
            'id' => $this->id,
            'name' => $name,
            'module' => $module,
            'display_name' => PermissionLabel::displayNameFromName($name),
            'guard_name' => $this->guard_name,
        ];
    }
}
