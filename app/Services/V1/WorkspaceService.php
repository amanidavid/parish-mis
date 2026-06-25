<?php

namespace App\Services\V1;

use App\Models\Landlord\BaseUser;
use App\Models\Landlord\UserTenant;
use App\Models\Tenancy\Tenant;
use App\Support\WorkspaceName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceService
{
    private const DEFAULT_CREATED_VIA = 'self_service';
    private const DEFAULT_TENANT_STATUS = 'active';
    private const DEFAULT_PROVISIONING_STATUS = 'pending';

    /**
     * Create workspace for user.
     */
    public function createWorkspaceForUser(BaseUser $baseUser, array $data, string $createdVia = self::DEFAULT_CREATED_VIA): Tenant
    {
        $normalizedName = WorkspaceName::normalize($data['name']);
        $displayName = WorkspaceName::display($data['name'], $data['display_name'] ?? null);
        $dbName = $this->normalizeDatabaseName($data['database'] ?? ('tenant_'.$normalizedName));

        return DB::connection('base')->transaction(function () use ($baseUser, $createdVia, $data, $dbName, $displayName, $normalizedName) {
            $ownedWorkspace = Tenant::query()
                ->select('tenants.id')
                ->join('user_tenants', 'user_tenants.tenant_id', '=', 'tenants.id')
                ->where('user_tenants.user_id', $baseUser->id)
                ->where('user_tenants.is_owner', true)
                ->lockForUpdate()
                ->first();

            if ($ownedWorkspace) {
                throw new \InvalidArgumentException(
                    'This account already has a workspace. Add properties inside the existing workspace instead of creating another database.'
                );
            }

            $exists = Tenant::query()
                ->where(fn ($query) => $query
                    ->where('name', $normalizedName)
                    ->orWhere('database', $dbName))
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw new \InvalidArgumentException('Workspace with the same name or database already exists.');
            }

            $tenant = Tenant::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $normalizedName,
                'display_name' => $displayName,
                'database' => $dbName,
                'status' => self::DEFAULT_TENANT_STATUS,
                'provisioning_status' => self::DEFAULT_PROVISIONING_STATUS,
                'provision_attempts' => 0,
                'meta' => [
                    'plan_uuid' => $data['plan_uuid'] ?? null,
                    'billing_rule_uuid' => $data['billing_rule_uuid'] ?? ($data['billing_profile_uuid'] ?? null),
                    'created_via' => $createdVia,
                ],
            ]);

            UserTenant::query()->updateOrCreate([
                'user_id' => $baseUser->id,
                'tenant_id' => $tenant->id,
            ], [
                'uuid' => (string) Str::uuid(),
                'is_owner' => true,
            ]);

            return $tenant;
        });
    }

    /**
     * Handle default workspace data for user.
     */
    public function defaultWorkspaceDataForUser(array $userData): array
    {
        $phoneDigits = preg_replace('/\D+/', '', (string) ($userData['phone'] ?? ''));
        $workspaceKey = 'workspace_'.($phoneDigits !== '' ? $phoneDigits : Str::lower(Str::random(12)));

        return [
            'name' => $workspaceKey,
            'display_name' => WorkspaceName::display($userData['name']).' Workspace',
            'database' => 'tenant_'.$workspaceKey,
            'plan_uuid' => null,
        ];
    }

    /**
     * Handle find owned workspace for user.
     */
    public function findOwnedWorkspaceForUser(int $baseUserId): ?Tenant
    {
        return Tenant::query()
            ->select('tenants.*')
            ->join('user_tenants', 'user_tenants.tenant_id', '=', 'tenants.id')
            ->where('user_tenants.user_id', $baseUserId)
            ->where('user_tenants.is_owner', true)
            ->orderBy('tenants.id')
            ->first();
    }

    /**
     * Normalize workspace database name to the same safe format used by workspace keys.
     */
    private function normalizeDatabaseName(string $database): string
    {
        $normalized = WorkspaceName::normalize($database);

        return str_starts_with($normalized, 'tenant_')
            ? $normalized
            : 'tenant_'.$normalized;
    }
}
