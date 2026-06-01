<?php

namespace App\Services\V1;

use App\Models\Tenant\Permission;
use App\Models\Tenant\Role;
use App\Support\PermissionLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class PermissionService
{
    public function findByIds(array $ids)
    {
        return Permission::query()
            ->where('guard_name', 'api')
            ->whereIn('id', $ids)
            ->orderBy('module')
            ->orderBy('name')
            ->get();
    }

    public function createPermission(string $name): Permission
    {
        $normalized = trim($name);
        $module = PermissionLabel::moduleFromName($normalized);

        $permission = Permission::query()->firstOrNew([
            'name' => $normalized,
            'guard_name' => 'api',
        ]);

        $permission->save();

        Permission::query()
            ->whereKey($permission->id)
            ->update([
                'module' => $module,
                'updated_at' => now(),
            ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Permission::query()->findOrFail($permission->id);
    }

    /** Create or update an API role and optionally attach the provided permissions in one transaction. */
    public function createRole(string $name, array $permissionIds = []): Role
    {
        return DB::connection((new Role())->getConnectionName())->transaction(function () use ($name, $permissionIds) {
            $normalized = strtolower(trim($name));

            $role = Role::query()->firstOrNew([
                'name' => $normalized,
                'guard_name' => 'api',
            ]);

            $role->save();

            if ($permissionIds !== []) {
                $permissions = $this->findByIds($permissionIds);

                if ($permissions->count() !== count($permissionIds)) {
                    throw ValidationException::withMessages([
                        'permission_ids' => ['One or more permissions are invalid.'],
                    ]);
                }

                $role->syncPermissions($permissions);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return Role::query()
                ->where('guard_name', 'api')
                ->withCount('permissions')
                ->with(['permissions' => fn ($permissionQuery) => $permissionQuery->orderBy('module')->orderBy('name')])
                ->findOrFail($role->id);
        });
    }

    /** Delete an API role, clear its assignments, and remove only permissions that become fully orphaned. */
    public function deleteRole(int $roleId): void
    {
        $connection = (new Role())->getConnectionName();

        DB::connection($connection)->transaction(function () use ($roleId, $connection) {
            $role = Role::query()
                ->where('guard_name', 'api')
                ->findOrFail($roleId);

            $attachedPermissionIds = DB::connection($connection)->table('role_has_permissions')
                ->where('role_id', $role->id)
                ->pluck('permission_id')
                ->all();

            $role->delete();

            if ($attachedPermissionIds !== []) {
                DB::connection($connection)->table('permissions')
                    ->where('guard_name', 'api')
                    ->whereIn('id', $attachedPermissionIds)
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('role_has_permissions')
                            ->whereColumn('role_has_permissions.permission_id', 'permissions.id');
                    })
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('model_has_permissions')
                            ->whereColumn('model_has_permissions.permission_id', 'permissions.id');
                    })
                    ->delete();
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    public function backfillModules(): void
    {
        Permission::query()->select(['id', 'name'])->chunkById(100, function ($permissions) {
            foreach ($permissions as $permission) {
                Permission::query()
                    ->whereKey($permission->id)
                    ->update([
                        'module' => PermissionLabel::moduleFromName($permission->name),
                        'updated_at' => now(),
                    ]);
            }
        });
    }
}
