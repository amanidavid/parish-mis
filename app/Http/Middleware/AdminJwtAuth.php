<?php

namespace App\Http\Middleware;

use App\Models\Landlord\SystemAdmin;
use App\Services\V1\JwtService;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;


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

            if ($userUuid === '') {
                throw new \Exception('User not found');
            }

            $session = SystemAdmin::query()
                ->join('users', 'users.id', '=', 'system_admins.user_id')
                ->where('users.uuid', $userUuid)
                ->select([
                    'users.id as user_id',
                    'users.uuid as user_uuid',
                    'users.username',
                    'users.name',
                    'users.email',
                    'users.phone',
                    'system_admins.id as admin_id',
                    'system_admins.uuid as admin_uuid',
                    'system_admins.super as admin_super',
                    'system_admins.scopes as admin_scopes',
                ])
                ->first();

            if (!$session) {
                throw new \Exception('System administrator access is required');
            }

            $user = (object) [
                'id' => (int) $session->user_id,
                'uuid' => $session->user_uuid,
                'username' => $session->username,
                'name' => $session->name,
                'email' => $session->email,
                'phone' => $session->phone,
            ];

            $adminScopes = is_string($session->admin_scopes)
                ? (json_decode($session->admin_scopes, true) ?: [])
                : Arr::wrap($session->admin_scopes);

            $admin = (object) [
                'id' => (int) $session->admin_id,
                'uuid' => $session->admin_uuid,
                'super' => (bool) $session->admin_super,
                'scopes' => $adminScopes,
            ];

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
