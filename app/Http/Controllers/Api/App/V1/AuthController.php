<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\App\V1\LoginRequest;
use App\Http\Requests\Api\App\V1\RegisterRequest;
use App\Http\Requests\Api\App\V1\ResetPasswordRequest;
use App\Http\Requests\Api\App\V1\VerifyOtpRequest;
use App\Http\Resources\App\V1\AppMeResource;
use App\Http\Resources\App\V1\AppSessionResource;
use App\Http\Resources\App\V1\OtpChallengeResource;
use App\Models\Landlord\BaseUser;
use App\Models\Landlord\OtpToken;
use App\Models\Tenancy\Tenant;
use App\Services\V1\JwtService;
use App\Services\V1\OtpService;
use App\Services\V1\SubscriptionService;
use App\Services\V1\TenantProvisioningService;
use App\Services\V1\WorkspaceService;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(
        private JwtService $jwt,
        private OtpService $otp,
        private SubscriptionService $subscriptionService,
        private TenantProvisioningService $tenantProvisioningService,
        private WorkspaceService $workspaceService,
    )
    {
    }

    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        $exists = BaseUser::query()
            ->where('username', $data['username'])
            ->orWhere('phone', $data['phone'])
            ->orWhere(function ($query) use ($data) {
                if (!empty($data['email'])) {
                    $query->where('email', $data['email']);
                }
            })
            ->exists();

        if ($exists) {
            return ApiResponse::error(
                'User already exists',
                ['user' => ['Username, phone or email already taken']],
                422
            );
        }

        [$baseUser, $workspace] = DB::connection('base')->transaction(function () use ($data) {
            $baseUser = BaseUser::query()->create([
                'uuid' => (string) Str::uuid(),
                'username' => $data['username'],
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'password' => Hash::make($data['password']),
                'status' => 'active',
                'meta' => [],
            ]);

            $workspace = $this->workspaceService->createWorkspaceForUser(
                $baseUser,
                $this->workspaceService->defaultWorkspaceDataForUser($data),
                'registration',
            );

            return [$baseUser, $workspace];
        });

        $this->tenantProvisioningService->dispatchProvisioning($workspace, $baseUser->id);

        return ApiResponse::success(
            'Registration successful. Your primary workspace was created and provisioning has been queued. Please login to receive OTP.',
            [
                'workspace_uuid' => $workspace->uuid,
                'workspace_name' => $workspace->display_name,
                'provisioning_status' => $workspace->provisioning_status,
            ],
            201
        );
    }

    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        $user = $this->resolveBaseUserForWorkspaceCredential($data);

        if (!$user || !Hash::check($data['password'], (string) $user->password)) {
            return ApiResponse::error('Invalid credentials', ['auth' => ['The provided credentials are incorrect.']], 401);
        }

        $otp = $this->otp->create((int) $user->id, 'login', 'log');

        return ApiResponse::resource(
            new OtpChallengeResource(['challenge_id' => $otp['challenge_id']]),
            'OTP sent',
            202
        );
    }

    public function verifyOtp(VerifyOtpRequest $request)
    {
        $data = $request->validated();

        if (!$this->otp->verify($data['challenge_id'], $data['code'], 'login')) {
            return ApiResponse::error('Invalid or expired OTP', ['otp' => ['Invalid or expired code']], 422);
        }

        $otpRow = OtpToken::query()->where('uuid', $data['challenge_id'])->firstOrFail();
        $baseUser = BaseUser::query()->findOrFail($otpRow->user_id);
        [$token, $expiresIn] = $this->jwt->issueTokenForSubject((string) $baseUser->uuid);

        return ApiResponse::resource(
            new AppSessionResource([
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => $expiresIn,
                'user' => $baseUser,
                'tenants' => $this->tenantMemberships($baseUser->id),
            ]),
            'OTP verified'
        );
    }

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $data = $request->validated();

        $user = $this->resolveBaseUserForWorkspaceCredential($data, false);

        if (!$user) {
            return ApiResponse::success('If the account exists, an OTP has been sent', null, 202);
        }

        $this->otp->create((int) $user->id, 'password_reset', 'log');

        return ApiResponse::success('OTP sent', null, 202);
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        $data = $request->validated();

        if (!$this->otp->verify($data['challenge_id'], $data['code'], 'password_reset')) {
            return ApiResponse::error('Invalid or expired OTP', ['otp' => ['Invalid or expired code']], 422);
        }

        $otpRow = OtpToken::query()->where('uuid', $data['challenge_id'])->firstOrFail();
        BaseUser::query()->where('id', $otpRow->user_id)->update([
            'password' => Hash::make($data['password']),
        ]);

        return ApiResponse::success('Password reset successful');
    }

    public function me()
    {
        $tenantUser = request()->attributes->get('tenant_user');
        if ($tenantUser) {
            $tenantUser->loadMissing([
                'roles.permissions',
                'permissions' => fn ($permissionQuery) => $permissionQuery->orderBy('module')->orderBy('name'),
            ]);
        }

        return ApiResponse::resource(new AppMeResource([
            'base_user' => request()->attributes->get('base_user'),
            'tenant' => request()->attributes->get('tenant'),
            'tenant_user' => $tenantUser,
            'subscription' => request()->attributes->get('tenant')
                ? $this->subscriptionService->getWorkspaceSubscriptionSummary(request()->attributes->get('tenant'))
                : null,
        ]), 'Current user');
    }

    public function refresh()
    {
        $baseUser = request()->attributes->get('base_user') ?? request()->attributes->get('auth_user');
        if (!$baseUser instanceof BaseUser) {
            return ApiResponse::error('Unauthorized', ['token' => ['Unable to resolve authenticated user']], 401);
        }

        [$token, $expiresIn] = $this->jwt->issueTokenForSubject((string) $baseUser->uuid);

        return ApiResponse::resource(
            new AppSessionResource([
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => $expiresIn,
                'user' => $baseUser,
                'tenants' => $this->tenantMemberships($baseUser->id),
            ]),
            'Token refreshed'
        );
    }

    public function logout()
    {
        return ApiResponse::success('Logged out');
    }

    private function tenantMemberships(int $baseUserId)
    {
        return DB::connection('base')->table('user_tenants')
            ->join('tenants', 'user_tenants.tenant_id', '=', 'tenants.id')
            ->where('user_tenants.user_id', $baseUserId)
            ->select(
                'tenants.uuid as tenant_uuid',
                'tenants.display_name as name',
                'tenants.status',
                'tenants.provisioning_status'
            )
            ->orderBy('tenants.display_name')
            ->get();
    }

    private function resolveBaseUserForWorkspaceCredential(array $data, bool $failOnAmbiguous = true): ?BaseUser
    {
        $workspaceUuid = $data['workspace_uuid'] ?? null;

        if (!empty($workspaceUuid)) {
            $workspace = Tenant::query()->where('uuid', $workspaceUuid)->first();
            if (!$workspace) {
                return null;
            }

            return BaseUser::query()
                ->select('users.*')
                ->join('user_tenants', 'user_tenants.user_id', '=', 'users.id')
                ->where('user_tenants.tenant_id', $workspace->id)
                ->when(!empty($data['phone'] ?? null), fn ($query) => $query->where('users.phone', $data['phone']))
                ->when(empty($data['phone'] ?? null), fn ($query) => $query->where('users.email', $data['email']))
                ->orderByDesc('user_tenants.is_owner')
                ->first();
        }

        $query = BaseUser::query();
        $query = !empty($data['phone'] ?? null)
            ? $query->where('phone', $data['phone'])
            : $query->where('email', $data['email']);

        $count = (clone $query)->count();
        if ($count > 1 && $failOnAmbiguous) {
            throw new HttpResponseException(ApiResponse::error(
                'Workspace selection is required.',
                ['workspace_uuid' => ['Provide the workspace identifier for this account.']],
                422
            ));
        }

        return $query->first();
    }
}
