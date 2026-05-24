<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class TenantUserResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        $hasRolePermissionsLoaded = $this->relationLoaded('roles')
            && $this->roles->every(fn ($role) => $role->relationLoaded('permissions'));
        $hasAccessRelations = $hasRolePermissionsLoaded || $this->relationLoaded('permissions');

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'base_user' => $this->whenLoaded('baseUser', fn () => $this->baseUser ? [
                'uuid' => $this->baseUser->uuid,
                'username' => $this->baseUser->username,
                'email' => $this->baseUser->email,
                'phone' => $this->baseUser->phone,
                'status' => $this->baseUser->status,
            ] : null),
            'roles' => UserRoleSummaryResource::collection($this->whenLoaded('roles')),
            'direct_permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
            'permissions' => $this->when($hasAccessRelations, function () {
                return PermissionResource::collection(
                    $this->getAllPermissions()
                        ->sortBy([
                            ['module', 'asc'],
                            ['name', 'asc'],
                        ])
                        ->values()
                );
            }),
            ...$this->timestamps(),
        ];
    }
}
