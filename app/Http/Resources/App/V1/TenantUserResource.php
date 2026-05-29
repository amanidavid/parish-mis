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
        $baseUser = $this->whenLoaded('baseUser');

        return [
            'uuid' => $this->uuid,
            'name' => $this->resolveProfileValue('name'),
            'email' => $this->resolveProfileValue('email'),
            'phone' => $this->resolveProfileValue('phone'),
            'username' => $baseUser?->username,
            'status' => $this->resolveProfileValue('status'),
            'base_user' => $this->whenLoaded('baseUser', fn () => $this->baseUser ? [
                'uuid' => $this->baseUser->uuid,
                'username' => $this->baseUser->username,
                'name' => $this->baseUser->name,
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

    private function resolveProfileValue(string $attribute): mixed
    {
        $value = $this->{$attribute};

        if ($value !== null && (!is_string($value) || trim($value) !== '')) {
            return $value;
        }

        if (!$this->relationLoaded('baseUser') || !$this->baseUser) {
            return $value;
        }

        return $this->baseUser->{$attribute};
    }
}
