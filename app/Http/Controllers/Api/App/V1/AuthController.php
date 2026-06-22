<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\ChangePasswordRequest;
use App\Http\Requests\Api\App\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\App\V1\LoginRequest;
use App\Http\Requests\Api\App\V1\RegisterRequest;
use App\Http\Requests\Api\App\V1\ResetPasswordRequest;
use App\Http\Requests\Api\App\V1\UpdateProfileRequest;
use App\Http\Requests\Api\App\V1\VerifyOtpRequest;
use App\Http\Resources\App\V1\AppMeResource;
use App\Http\Resources\App\V1\AppSessionResource;
use App\Http\Resources\App\V1\OtpChallengeResource;
use App\Models\Landlord\BaseUser;
use App\Models\Landlord\OtpToken;
use App\Models\Landlord\UserTenant;
use App\Models\Tenant\Country;
use App\Models\Tenant\User as TenantUser;
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
use RuntimeException;

class AuthController extends Controller
{
    /**
     * Create a new instance.
     */
    public function __construct(
        private JwtService $jwt,
        private OtpService $otp,
        private SubscriptionService $subscriptionService,
        private TenantProvisioningService $tenantProvisioningService,
        private WorkspaceService $workspaceService,
    )
    {
    }

    /**
     * Handle the register request.
     */
    public function register(RegisterRequest $request)
    {
        $data = $request->validated();
        $phone = $this->composePhoneNumber($data['country_code'] ?? null, $data['phone'] ?? null);
        $email = $this->normalizeEmail($data['email'] ?? null);
        $username = $this->resolveRegistrationUsername($data['username'] ?? null, $data['name'], $phone);

        $exists = BaseUser::query()
            ->where('username', $username)
            ->orWhere('phone', $phone)
            ->orWhere(function ($query) use ($data) {
                $email = $this->normalizeEmail($data['email'] ?? null);
                if (!empty($email)) {
                    $query->where('email', $email);
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

        [$baseUser, $workspace] = DB::connection('base')->transaction(function () use ($data, $email, $phone, $username) {
            $baseUser = BaseUser::query()->create([
                'uuid' => (string) Str::uuid(),
                'username' => $username,
                'name' => $data['name'],
                'phone' => $phone,
                'email' => $email,
                'password' => Hash::make($data['password']),
                'status' => 'active',
                'meta' => [
                    'country_code' => $this->normalizeCountryCode($data['country_code'] ?? null),
                ],
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

    /**
     * Handle the login request.
     */
    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        $user = $this->resolveBaseUserForCredential($data);

        if (!$user || !Hash::check($data['password'], (string) $user->password)) {
            return ApiResponse::error('Invalid credentials', ['auth' => ['The provided credentials are incorrect.']], 401);
        }

        [$token, $expiresIn] = $this->jwt->issueTokenForSubject((string) $user->uuid);

        return ApiResponse::resource(
            new AppSessionResource([
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => $expiresIn,
                'user' => $user,
                'tenants' => $this->tenantMemberships($user->id),
            ]),
            'Login successful.'
        );
    }

    /**
     * Handle the verify otp request.
     */
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

    /**
     * Handle the forgot password request.
     */
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $data = $request->validated();

        $user = $this->resolveBaseUserForCredential($data, false);

        if (!$user) {
            return ApiResponse::success('If the account exists, an OTP has been sent', null, 202);
        }

        try {
            $otp = $this->otp->create((int) $user->id, 'password_reset');
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

    /**
     * Handle the me request.
     */
    public function me()
    {
        $baseUser = request()->attributes->get('base_user');
        $tenantUser = request()->attributes->get('tenant_user');
        if ($tenantUser) {
            $tenantUser->loadMissing([
                'roles.permissions',
                'permissions' => fn ($permissionQuery) => $permissionQuery->orderBy('module')->orderBy('name'),
            ]);
        }

        return ApiResponse::resource(new AppMeResource([
            'base_user' => $baseUser,
            'country' => $baseUser instanceof BaseUser ? $this->resolveBaseUserCountry($baseUser) : null,
            'tenant' => request()->attributes->get('tenant'),
            'tenant_user' => $tenantUser,
            'subscription' => request()->attributes->get('tenant')
                ? $this->subscriptionService->getWorkspaceSubscriptionSummary(request()->attributes->get('tenant'))
                : null,
        ]), 'Current user');
    }

    /**
     * Handle the refresh request.
     */
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

    /**
     * Handle the logout request.
     */
    public function logout()
    {
        return ApiResponse::success('Logged out');
    }

    /**
     * Handle the change password request.
     */
    public function changePassword(ChangePasswordRequest $request)
    {
        $baseUser = request()->attributes->get('base_user') ?? request()->attributes->get('auth_user');
        if (!$baseUser instanceof BaseUser) {
            return ApiResponse::error('Unauthorized', ['token' => ['Unable to resolve authenticated user']], 401);
        }

        $data = $request->validated();

        if (!Hash::check($data['current_password'], (string) $baseUser->password)) {
            return ApiResponse::error(
                'Password could not be changed.',
                ['current_password' => ['The current password you entered is incorrect.']],
                422
            );
        }

        DB::connection('base')->transaction(function () use ($baseUser, $data) {
            $baseUser->forceFill([
                'password' => Hash::make($data['password']),
            ])->save();
        });

        return ApiResponse::success('Password changed successfully.');
    }

    /**
     * Handle the update profile request.
     */
    public function updateProfile(UpdateProfileRequest $request)
    {
        $baseUser = request()->attributes->get('base_user') ?? request()->attributes->get('auth_user');
        if (!$baseUser instanceof BaseUser) {
            return ApiResponse::error('Unauthorized', ['token' => ['Unable to resolve authenticated user']], 401);
        }

        $tenantUser = request()->attributes->get('tenant_user');
        $data = $request->validated();
        $existingCountryCode = $this->normalizeCountryCode(data_get($baseUser->meta, 'country_code'));
        $countryCode = array_key_exists('country_code', $data)
            ? $this->normalizeCountryCode($data['country_code'])
            : $existingCountryCode;
        $newPhone = array_key_exists('phone', $data)
            ? $this->composePhoneNumber($countryCode, $data['phone'])
            : $baseUser->phone;
        $newEmail = array_key_exists('email', $data) ? $this->normalizeEmail($data['email']) : $baseUser->email;
        $newUsername = array_key_exists('username', $data) ? $this->normalizeUsername($data['username']) : $baseUser->username;
        $newName = $data['name'] ?? $baseUser->name;

        $baseConflict = BaseUser::query()
            ->where(function ($query) use ($newPhone, $newEmail, $newUsername) {
                $query->where('phone', $newPhone);

                if (!empty($newEmail)) {
                    $query->orWhere('email', $newEmail);
                }

                if (!empty($newUsername)) {
                    $query->orWhere('username', $newUsername);
                }
            })
            ->whereKeyNot($baseUser->id)
            ->exists();

        if ($baseConflict) {
            return ApiResponse::error('Profile could not be updated.', [
                'user' => ['Phone, email, or username is already in use.'],
            ], 422);
        }

        if ($tenantUser instanceof TenantUser) {
            $tenantConflict = TenantUser::query()
                ->where(function ($query) use ($newPhone, $newEmail) {
                    $query->where('phone', $newPhone);

                    if (!empty($newEmail)) {
                        $query->orWhere('email', $newEmail);
                    }
                })
                ->whereKeyNot($tenantUser->id)
                ->exists();

            if ($tenantConflict) {
                return ApiResponse::error('Profile could not be updated.', [
                    'user' => ['Phone or email already belongs to another workspace account.'],
                ], 422);
            }
        }

        DB::connection('base')->transaction(function () use ($baseUser, $newUsername, $newName, $newPhone, $newEmail, $countryCode) {
            $baseUser->forceFill([
                'username' => $newUsername,
                'name' => $newName,
                'phone' => $newPhone,
                'email' => $newEmail,
                'meta' => array_merge((array) $baseUser->meta, [
                    'country_code' => $countryCode !== '' ? $countryCode : null,
                ]),
            ])->save();
        });

        if ($tenantUser instanceof TenantUser) {
            DB::transaction(function () use ($tenantUser, $newName, $newPhone, $newEmail) {
                $tenantUser->forceFill([
                    'name' => $newName,
                    'phone' => $newPhone,
                    'email' => $newEmail,
                ])->save();
            });
        }

        $freshBaseUser = BaseUser::query()->findOrFail($baseUser->id);
        $freshTenantUser = $tenantUser instanceof TenantUser
            ? $tenantUser->fresh(['roles.permissions', 'permissions' => fn ($permissionQuery) => $permissionQuery->orderBy('module')->orderBy('name')])
            : null;

        return ApiResponse::resource(
            new AppMeResource([
                'base_user' => $freshBaseUser,
                'country' => $this->resolveBaseUserCountry($freshBaseUser),
                'tenant' => request()->attributes->get('tenant'),
                'tenant_user' => $freshTenantUser,
                'subscription' => request()->attributes->get('tenant')
                    ? $this->subscriptionService->getWorkspaceSubscriptionSummary(request()->attributes->get('tenant'))
                    : null,
            ]),
            'Profile updated successfully.'
        );
    }

    /**
     * Tenant memberships.
     */
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

    /**
     * Resolve base user for credential.
     */
    private function resolveBaseUserForCredential(array $data, bool $failOnAmbiguous = true): ?BaseUser
    {
        $phone = !empty($data['phone'] ?? null)
            ? $this->composePhoneNumber($data['country_code'] ?? null, $data['phone'])
            : null;
        $email = $this->normalizeEmail($data['email'] ?? null);

        $query = BaseUser::query();
        $query = $phone !== null
            ? $query->where('phone', $phone)
            : $query->where('email', $email);

        $count = (clone $query)->count();
        if ($count > 1 && $failOnAmbiguous) {
            return $this->throwSingleAccountRequired();
        }

        $user = $query->first();

        if (!$user) {
            return null;
        }

        $membershipCount = UserTenant::query()->where('user_id', $user->id)->count();

        if ($membershipCount > 1) {
            return $this->throwSingleWorkspaceRequired();
        }

        return $membershipCount === 1 ? $user : null;
    }

    /**
     * Throw single account required.
     */
    private function throwSingleAccountRequired(): never
    {
        throw new HttpResponseException(ApiResponse::error(
            'This phone or email matches multiple accounts.',
            ['auth' => ['Please contact support because this account is linked more than once.']],
            422
        ));
    }

    /**
     * Throw single workspace required.
     */
    private function throwSingleWorkspaceRequired(): never
    {
        throw new HttpResponseException(ApiResponse::error(
            'This account is linked to multiple workspaces.',
            ['auth' => ['This login flow supports one account for one workspace only. Please contact support.']],
            422
        ));
    }

    /**
     * Resolve registration username.
     */
    private function resolveRegistrationUsername(?string $username, string $name, string $phone): string
    {
        $username = $this->normalizeUsername($username);

        if ($username !== null) {
            return $username;
        }

        $base = Str::of($name)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        if ($base === '') {
            $base = 'user';
        }

        $phoneDigits = preg_replace('/\D+/', '', $phone);
        $candidate = $base.'_'.substr($phoneDigits !== '' ? $phoneDigits : Str::random(6), -6);
        $sequence = 1;

        while (BaseUser::query()->where('username', $candidate)->exists()) {
            $candidate = $base.'_'.$sequence;
            $sequence++;
        }

        return $candidate;
    }

    /**
     * Compose phone number.
     */
    private function composePhoneNumber(?string $countryCode, ?string $phone): string
    {
        $normalizedCountryCode = $this->normalizeCountryCode($countryCode);
        $normalizedPhone = preg_replace('/\D+/', '', (string) $phone);
        $normalizedPhone = ltrim((string) $normalizedPhone, '0');

        return $normalizedCountryCode.($normalizedPhone !== '' ? $normalizedPhone : '');
    }

    /**
     * Normalize country code.
     */
    private function normalizeCountryCode(?string $countryCode): string
    {
        $countryCode = preg_replace('/[^0-9+]/', '', (string) $countryCode);
        $countryCode = trim((string) $countryCode);

        if ($countryCode === '') {
            return '';
        }

        return str_starts_with($countryCode, '+') ? $countryCode : '+'.$countryCode;
    }

    /**
     * Normalize email.
     */
    private function normalizeEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email !== '' ? Str::lower($email) : null;
    }

    /**
     * Normalize username.
     */
    private function normalizeUsername(?string $username): ?string
    {
        $username = trim((string) $username);

        return $username !== '' ? Str::lower($username) : null;
    }

    /**
     * Resolve base user country.
     */
    private function resolveBaseUserCountry(BaseUser $baseUser): ?Country
    {
        $countryCode = $this->normalizeCountryCode(data_get($baseUser->meta, 'country_code'));

        if ($countryCode === '') {
            return null;
        }

        return Country::query()
            ->select(['id', 'uuid', 'name', 'code', 'dial_code'])
            ->where('dial_code', $countryCode)
            ->first();
    }
}
