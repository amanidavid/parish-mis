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
    public function createWorkspaceForUser(BaseUser $baseUser, array $data, string $createdVia = 'self_service'): Tenant
    {
        $existingWorkspace = $this->findOwnedWorkspaceForUser($baseUser->id);
        if ($existingWorkspace) {
            throw new \InvalidArgumentException(
                'This account already has a workspace. Add properties inside the existing workspace instead of creating another database.'
            );
        }

        $normalizedName = WorkspaceName::normalize($data['name']);
        $displayName = WorkspaceName::display($data['name'], $data['display_name'] ?? null);
        $dbName = $data['database'] ?? ('tenant_'.$normalizedName);

        $exists = Tenant::query()
            ->where(fn ($query) => $query
                ->where('name', $normalizedName)
                ->orWhere('database', $dbName))
            ->exists();

        if ($exists) {
            throw new \InvalidArgumentException('Workspace with the same name or database already exists.');
        }

        return DB::connection('base')->transaction(function () use ($baseUser, $createdVia, $data, $dbName, $displayName, $normalizedName) {
            $tenant = Tenant::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $normalizedName,
                'display_name' => $displayName,
                'database' => $dbName,
                'status' => 'active',
                'provisioning_status' => 'pending',
                'provision_attempts' => 0,
                'meta' => [
                    'plan_uuid' => $data['plan_uuid'] ?? null,
                    'billing_profile_uuid' => $data['billing_profile_uuid'] ?? null,
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
}
