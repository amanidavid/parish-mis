<?php

namespace App\Http\Resources\Admin\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'access_token' => $this['access_token'],
            'token_type' => $this['token_type'],
            'expires_in' => $this['expires_in'],
            'user' => [
                'id' => $this['user']->id,
                'uuid' => $this['user']->uuid,
                'username' => $this['user']->username,
                'name' => $this['user']->name,
                'email' => $this['user']->email,
                'phone' => $this['user']->phone,
            ],
            'admin' => [
                'id' => $this['admin']->id,
                'uuid' => $this['admin']->uuid,
                'super' => (bool) $this['admin']->super,
                'scopes' => $this['admin']->scopes ?? [],
            ],
        ];
    }
}
