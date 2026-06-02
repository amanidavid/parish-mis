<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\V1\AdminLoginRequest;
use App\Http\Resources\Admin\V1\AdminSessionResource;
use App\Models\Landlord\BaseUser;
use App\Services\V1\JwtService;
use App\Support\ApiResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private JwtService $jwt)
    {
    }

    public function login(AdminLoginRequest $request)
    {
        $data = $request->validated();

        $query = BaseUser::query()
            ->join('system_admins', 'system_admins.user_id', '=', 'users.id')
            ->select([
                'users.id as user_id',
                'users.uuid as user_uuid',
                'users.username',
                'users.name',
                'users.email',
                'users.phone',
                'users.password',
                'system_admins.id as admin_id',
                'system_admins.uuid as admin_uuid',
                'system_admins.super as admin_super',
                'system_admins.scopes as admin_scopes',
            ]);

        $session = !empty($data['username'] ?? null)
            ? $query->where('users.username', $data['username'])->first()
            : $query->where('users.email', $data['email'])->first();

        if (!$session || !Hash::check($data['password'], (string) $session->password)) {
            return ApiResponse::error('Invalid credentials', ['auth' => ['The provided credentials are incorrect.']], 401);
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

        [$token, $expiresIn] = $this->jwt->issueAdminTokenForSubject((string) $user->uuid);

        return ApiResponse::resource(new AdminSessionResource([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $expiresIn,
            'user' => $user,
            'admin' => $admin,
        ]), 'Login successful');
    }

    public function me()
    {
        $user = request()->attributes->get('admin_user');
        $admin = request()->attributes->get('system_admin');

        return ApiResponse::resource(new AdminSessionResource([
            'access_token' => null,
            'token_type' => 'bearer',
            'expires_in' => null,
            'user' => $user,
            'admin' => $admin,
        ]), 'Current admin session');
    }

    public function logout()
    {
        return ApiResponse::success('Logged out');
    }
}
