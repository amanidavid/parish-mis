<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\V1\AdminLoginRequest;
use App\Http\Resources\Admin\V1\AdminSessionResource;
use App\Models\Landlord\BaseUser;
use App\Models\Landlord\SystemAdmin;
use App\Services\V1\JwtService;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private JwtService $jwt)
    {
    }

    public function login(AdminLoginRequest $request)
    {
        $data = $request->validated();

        $query = BaseUser::query();
        $user = !empty($data['username'] ?? null)
            ? $query->where('username', $data['username'])->first()
            : $query->where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], (string) $user->password)) {
            return ApiResponse::error('Invalid credentials', ['auth' => ['The provided credentials are incorrect.']], 401);
        }

        $admin = SystemAdmin::query()->where('user_id', $user->id)->first();
        if (!$admin) {
            return ApiResponse::error('Access denied', ['auth' => ['System administrator access is required.']], 403);
        }

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
