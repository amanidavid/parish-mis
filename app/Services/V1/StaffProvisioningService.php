<?php

namespace App\Services\V1;

use App\Models\Landlord\BaseUser;
use App\Models\Landlord\UserTenant;
use App\Models\Tenant\Role;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StaffProvisioningService
{
    private const DEFAULT_ROLE = 'manager';
    private const DEFAULT_STATUS = 'active';

    /**
     * Create tenant staff.
     */
    public function createTenantStaff(Tenant $tenant, array $data): array
    {
        $this->assertTenantReady($tenant);

        $password = $data['password'] ?? $this->generateTemporaryPassword();
        $username = $this->resolveUniqueUsername($data['username'] ?? null, $data['name']);
        $roles = $this->resolveRoleNames($data['roles'] ?? [self::DEFAULT_ROLE]);
        $status = $data['status'] ?? self::DEFAULT_STATUS;

        $this->guardAgainstWorkspaceStaffConflicts($data['phone'], $data['email'] ?? null);
        $this->guardAgainstBaseUserConflicts($data['phone'], $data['email'] ?? null, $username);

        $baseUser = null;

        try {
            $baseUser = DB::connection('base')->transaction(function () use ($tenant, $data, $password, $username, $status) {
                $baseUser = BaseUser::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'username' => $username,
                    'name' => trim($data['name']),
                    'phone' => $data['phone'],
                    'email' => $data['email'] ?? null,
                    'password' => Hash::make($password),
                    'status' => $status,
                    'meta' => [
                        'credential_delivery_channel' => 'log',
                        'password_generated_by_landlord' => !isset($data['password']),
                    ],
                ]);

                UserTenant::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $baseUser->id,
                    'tenant_id' => $tenant->id,
                    'is_owner' => false,
                ]);

                return $baseUser;
            });

            $tenantUser = DB::transaction(function () use ($baseUser, $data, $roles, $status) {
                $tenantUser = TenantUser::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'base_user_id' => $baseUser->id,
                    'name' => trim($data['name']),
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'],
                    'status' => $status,
                ]);

                $tenantUser->syncRoles($roles);

                return $tenantUser;
            });
        } catch (\Throwable $e) {
            if ($baseUser) {
                DB::connection('base')->transaction(function () use ($baseUser, $tenant) {
                    UserTenant::query()
                        ->where('user_id', $baseUser->id)
                        ->where('tenant_id', $tenant->id)
                        ->delete();

                    BaseUser::query()->whereKey($baseUser->id)->delete();
                });
            }

            throw $e;
        }

        Log::info('[STAFF_CREDENTIALS] tenant_uuid='.$tenant->uuid
            .' base_user_uuid='.$baseUser->uuid
            .' username='.$baseUser->username
            .' phone='.$baseUser->phone
            .' email='.($baseUser->email ?? 'null')
            .' temporary_password='.$password
            .' channel=log');

        return [
            'base_user' => $baseUser,
            'tenant_user' => $tenantUser->fresh()->load('baseUser'),
            'roles' => $roles,
            'delivery_channel' => 'log',
            'password_generated' => !isset($data['password']),
        ];
    }

    /**
     * Resolve role names.
     */
    private function resolveRoleNames(array $roleNames): array
    {
        $normalized = collect($roleNames)
            ->filter(fn ($roleName) => is_string($roleName) && $roleName !== '')
            ->map(fn ($roleName) => trim($roleName))
            ->unique()
            ->values()
            ->all();

        $normalized = $normalized === [] ? [self::DEFAULT_ROLE] : $normalized;
        if (in_array('owner', $normalized, true)) {
            throw new \InvalidArgumentException('Owner role cannot be assigned through staff provisioning.');
        }

        $count = Role::query()->where('guard_name', 'api')->whereIn('name', $normalized)->count();
        if ($count !== count($normalized)) {
            throw new \InvalidArgumentException('One or more tenant roles are invalid.');
        }

        return $normalized;
    }

    /**
     * Guard against workspace staff conflicts.
     */
    private function guardAgainstWorkspaceStaffConflicts(string $phone, ?string $email): void
    {
        $query = TenantUser::query()
            ->where('phone', $phone);

        if (!empty($email)) {
            $query->orWhere('email', $email);
        }

        if ($query->exists()) {
            throw new \InvalidArgumentException('A staff account with the same phone or email already exists in this workspace.');
        }
    }

    /**
     * Guard against global base-user conflicts before the landlord insert is attempted.
     */
    private function guardAgainstBaseUserConflicts(string $phone, ?string $email, string $username): void
    {
        if (BaseUser::query()->where('username', $username)->exists()) {
            throw new \InvalidArgumentException('The selected username is already in use.');
        }

        if (BaseUser::query()->where('phone', $phone)->exists()) {
            throw new \InvalidArgumentException('The selected phone number is already linked to another account.');
        }

        if (!empty($email) && BaseUser::query()->where('email', $email)->exists()) {
            throw new \InvalidArgumentException('The selected email address is already linked to another account.');
        }
    }

    /**
     * Allow staff provisioning only after the workspace tenant database is ready.
     */
    private function assertTenantReady(Tenant $tenant): void
    {
        if ($tenant->provisioning_status !== 'ready' || empty($tenant->database)) {
            throw new \InvalidArgumentException('This workspace is not ready for staff provisioning yet.');
        }
    }

    /**
     * Resolve unique username.
     */
    private function resolveUniqueUsername(?string $requestedUsername, string $name): string
    {
        $base = !empty($requestedUsername)
            ? Str::lower(trim($requestedUsername))
            : Str::lower(Str::slug($name, '.'));
        $base = $base !== '' ? $base : 'staff.user';
        $candidate = $base;
        $suffix = 1;

        while (BaseUser::query()->where('username', $candidate)->exists()) {
            $suffix++;
            $candidate = $base.'.'.$suffix;
        }

        return $candidate;
    }

    /**
     * Generate temporary password.
     */
    private function generateTemporaryPassword(int $length = 10): string
    {
        return Str::upper(Str::random(4)).random_int(1000, 9999).Str::lower(Str::random(max(2, $length - 8)));
    }
}
