<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\V1\AdminForgotPasswordRequest;
use App\Http\Requests\Api\Admin\V1\AdminLoginRequest;
use App\Http\Requests\Api\Admin\V1\AdminResetPasswordRequest;
use App\Http\Resources\App\V1\OtpChallengeResource;
use App\Http\Resources\Admin\V1\AdminSessionResource;
use App\Models\Landlord\BaseUser;
use App\Models\Landlord\OtpToken;
use App\Services\V1\JwtService;
use App\Services\V1\OtpService;
use App\Support\ApiResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AuthController extends Controller
{
    /**
     * Create a new instance.
     */
    public function __construct(
        private JwtService $jwt,
        private OtpService $otp,
    )
    {
    }

    /**
     * Handle the login request.
     */
    public function login(AdminLoginRequest $request)
    {
        $data = $request->validated();
        $session = $this->resolveAdminSession($data);

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

    /**
     * Handle the forgot password request.
     */
    public function forgotPassword(AdminForgotPasswordRequest $request)
    {
        $session = $this->resolveAdminSession($request->validated());

        if (!$session) {
            return ApiResponse::success('If the admin account exists, an OTP has been sent', null, 202);
        }

        try {
            $otp = $this->otp->create((int) $session->user_id, 'password_reset');
        } catch (RuntimeException $exception) {
            report($exception);

            return ApiResponse::error(
                'OTP could not be sent at this time.',
                ['otp' => ['Please try again in a moment.']],
                503
            );
        }

        return ApiResponse::resource(
            new OtpChallengeResource(['challenge_id' => $otp['challenge_id']]),
            'OTP sent',
            202
        );
    }

    /**
     * Handle the reset password request.
     */
    public function resetPassword(AdminResetPasswordRequest $request)
    {
        $data = $request->validated();

        if (!$this->otp->verify($data['challenge_id'], $data['code'], 'password_reset')) {
            return ApiResponse::error('Invalid or expired OTP', ['otp' => ['Invalid or expired code']], 422);
        }

        $otpRow = OtpToken::query()->where('uuid', $data['challenge_id'])->firstOrFail();
        $adminExists = BaseUser::query()
            ->join('system_admins', 'system_admins.user_id', '=', 'users.id')
            ->where('users.id', $otpRow->user_id)
            ->exists();

        if (!$adminExists) {
            return ApiResponse::error(
                'Password reset failed.',
                ['auth' => ['The selected reset challenge does not belong to an admin account.']],
                422
            );
        }

        BaseUser::query()->where('id', $otpRow->user_id)->update([
            'password' => Hash::make($data['password']),
        ]);

        return ApiResponse::success('Admin password reset successful');
    }

    /**
     * Handle the me request.
     */
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

    /**
     * Handle the logout request.
     */
    public function logout()
    {
        return ApiResponse::success('Logged out');
    }

    /**
     * Resolve the admin session row.
     */
    private function resolveAdminSession(array $data): ?object
    {
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

        if (!empty($data['username'] ?? null)) {
            $query->where('users.username', trim((string) $data['username']));
        } else {
            $query->where('users.email', strtolower(trim((string) ($data['email'] ?? ''))));
        }

        return $query->first();
    }
}
