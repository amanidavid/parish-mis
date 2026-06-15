<?php

namespace App\Http\Resources\App\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppMeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $country = $this['country'];
        $tenantUser = $this['tenant_user'];
        $baseUserPayload = [
            'uuid' => $this['base_user']->uuid,
            'username' => $this['base_user']->username,
            'name' => $this['base_user']->name,
            'email' => $this['base_user']->email,
            'phone' => $this['base_user']->phone,
            'country' => $country ? [
                'uuid' => $country->uuid,
                'name' => $country->name,
                'code' => $country->code,
                'dial_code' => $country->dial_code,
            ] : null,
        ];

        return [
            'user' => $baseUserPayload,
            'base_user' => $baseUserPayload,
            'tenant' => $this['tenant'] ? [
                'uuid' => $this['tenant']->uuid,
                'name' => $this['tenant']->name,
                'display_name' => $this['tenant']->display_name,
            ] : null,
            'tenant_user' => $tenantUser ? [
                'uuid' => $tenantUser->uuid,
                'name' => $tenantUser->name,
                'email' => $tenantUser->email,
                'phone' => $tenantUser->phone,
                'status' => $tenantUser->status,
                'roles' => UserRoleSummaryResource::collection($tenantUser->roles),
                'direct_permissions' => PermissionResource::collection($tenantUser->permissions),
                'permissions' => PermissionResource::collection(
                    $tenantUser->getAllPermissions()
                        ->sortBy([
                            ['module', 'asc'],
                            ['name', 'asc'],
                        ])
                        ->values()
                ),
            ] : null,
            'subscription' => $this['subscription'] ?? null,
        ];
    }
}
