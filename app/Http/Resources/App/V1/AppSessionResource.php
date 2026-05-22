<?php

namespace App\Http\Resources\App\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'access_token' => $this['access_token'],
            'token_type' => $this['token_type'],
            'expires_in' => $this['expires_in'],
            'user' => [
                'uuid' => $this['user']->uuid,
                'username' => $this['user']->username,
                'name' => $this['user']->name,
                'email' => $this['user']->email,
                'phone' => $this['user']->phone,
            ],
            'tenants' => TenantWorkspaceResource::collection(collect($this['tenants'])),
        ];
    }
}
