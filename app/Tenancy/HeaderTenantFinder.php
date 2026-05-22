<?php

namespace App\Tenancy;

use Illuminate\Http\Request;
use App\Models\Tenancy\Tenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;

class HeaderTenantFinder extends TenantFinder
{
    public function findForRequest(Request $request): ?Tenant
    {
        $uuid = (string) $request->header('X-Tenant-Id', '');
        if ($uuid === '' || !preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
            return null;
        }
        return Tenant::query()->where('uuid', $uuid)->first();
    }
}
