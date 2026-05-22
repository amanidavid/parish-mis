<?php

namespace App\Http\Middleware;

use App\Models\Landlord\BaseUser;
use App\Models\Landlord\SystemAdmin;
use App\Services\V1\JwtService;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;


class AdminJwtAuth
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
            $user = BaseUser::query()->where('uuid', $userUuid)->first();
            if (!$user) {
                throw new \Exception('User not found');
            }
            $admin = SystemAdmin::query()->where('user_id', $user->id)->first();
            if (!$admin) {
                throw new \Exception('System administrator access is required');
            }

            $request->attributes->set('admin_user', $user);
            $request->attributes->set('system_admin', $admin);
            $request->setUserResolver(fn () => $user);
        } catch (\Throwable $e) {
            return ApiResponse::unauthorized(
                ['token' => [ApiMessages::INVALID_SESSION]],
                ApiMessages::AUTHENTICATION_REQUIRED
            );
        }

        return $next($request);
    }
}
