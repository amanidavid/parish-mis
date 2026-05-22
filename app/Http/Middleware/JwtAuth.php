<?php

namespace App\Http\Middleware;

use App\Models\Landlord\BaseUser;
use App\Models\Tenant\User as TenantUser;
use App\Services\V1\JwtService;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;

class JwtAuth
{
    public function __construct(private JwtService $jwtService)
    {
    }

    private function bearerToken(Request $request): ?string
    {
        $auth = $request->header('Authorization', '');
        if (preg_match('/Bearer\s+(.*)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        $cookie = $request->cookie('access_token');
        if (!empty($cookie)) {
            return $cookie;
        }
        return null;
    }

    public function handle(Request $request, Closure $next)
    {
        $jwt = $this->bearerToken($request);
        if (!$jwt) {
            return ApiResponse::unauthorized(
                ['token' => ['A valid access token is required.']],
                ApiMessages::AUTHENTICATION_REQUIRED
            );
        }

        try {
            $payload = $this->jwtService->decodeToken($jwt);
            $userUuid = (string) ($payload['sub'] ?? '');
            $baseUser = BaseUser::query()->where('uuid', $userUuid)->first();
            if (!$baseUser) {
                throw new \Exception('User not found');
            }

            $request->attributes->set('auth_user', $baseUser);
            $request->attributes->set('base_user', $baseUser);

            $tenant = $request->attributes->get('tenant');
            if ($tenant) {
                $tenantUser = TenantUser::query()->where('base_user_id', $baseUser->id)->first();
                if (!$tenantUser) {
                    throw new \Exception('Tenant user profile not found for this workspace');
                }

                app('auth')->shouldUse('api');
                app('auth')->guard('api')->setUser($tenantUser);
                $request->attributes->set('tenant_user', $tenantUser);
                $request->setUserResolver(fn () => $tenantUser);
            } else {
                app('auth')->shouldUse('web');
                app('auth')->guard('web')->setUser($baseUser);
                $request->setUserResolver(fn () => $baseUser);
            }
        } catch (\Throwable $e) {
            return ApiResponse::unauthorized(
                ['token' => [ApiMessages::INVALID_SESSION]],
                ApiMessages::AUTHENTICATION_REQUIRED
            );
        }

        return $next($request);
    }
}
