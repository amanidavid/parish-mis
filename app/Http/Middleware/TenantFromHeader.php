<?php

namespace App\Http\Middleware;

use App\Models\Tenancy\Tenant;
use App\Support\ApiMessages;
use App\Tenancy\HeaderTenantFinder;
use Closure;
use Illuminate\Http\Request;
use App\Support\ApiResponse;

class TenantFromHeader
{
    public function __construct(private HeaderTenantFinder $finder)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $tenantUuid = (string) $request->header('X-Tenant-Id', '');
        if ($tenantUuid === '' || !preg_match('/^[0-9a-fA-F-]{36}$/', $tenantUuid)) {
            return ApiResponse::badRequest(
                ['tenant' => ['Provide a valid X-Tenant-Id header.']],
                ApiMessages::INVALID_TENANT_HEADER
            );
        }

        $tenant = $this->finder->findForRequest($request);
        if (!$tenant) {
            return ApiResponse::notFound(
                ['tenant' => ['No workspace was found for the provided X-Tenant-Id header.']],
                ApiMessages::TENANT_NOT_FOUND
            );
        }

        $request->attributes->set('tenant_uuid', $tenantUuid);
        $tenant->makeCurrent();
        $request->attributes->set('tenant', $tenant);

        try {
            return $next($request);
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
