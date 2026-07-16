<?php

use App\Models\Tenant\User;
use App\Support\PermissionLabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $timestamp = now();
        $permissions = [
            'staff.manage',
            'staff.view',
            'staff.create',
            'staff.update',
            'staff.delete',
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                [
                    'name' => $permission,
                    'guard_name' => 'api',
                ],
                [
                    'name' => $permission,
                    'module' => PermissionLabel::moduleFromName($permission),
                    'guard_name' => 'api',
                    'updated_at' => $timestamp,
                    'created_at' => $timestamp,
                ]
            );
        }

        $managerRoleId = DB::table('roles')
            ->where('name', 'manager')
            ->where('guard_name', 'api')
            ->value('id');

        if (!$managerRoleId) {
            return;
        }

        $staffPermissionIds = DB::table('permissions')
            ->where('guard_name', 'api')
            ->whereIn('name', $permissions)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($staffPermissionIds === []) {
            return;
        }

        DB::table('role_has_permissions')
            ->where('role_id', $managerRoleId)
            ->whereIn('permission_id', $staffPermissionIds)
            ->delete();

        $managerUserIds = DB::table('model_has_roles')
            ->where('role_id', $managerRoleId)
            ->where('model_type', User::class)
            ->pluck('model_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($managerUserIds !== []) {
            DB::table('model_has_permissions')
                ->where('model_type', User::class)
                ->whereIn('model_id', $managerUserIds)
                ->whereIn('permission_id', $staffPermissionIds)
                ->delete();
        }
    }

    public function down(): void
    {
        $managerRoleId = DB::table('roles')
            ->where('name', 'manager')
            ->where('guard_name', 'api')
            ->value('id');

        if (!$managerRoleId) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'api')
            ->whereIn('name', ['staff.manage'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($permissionIds as $permissionId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $managerRoleId,
            ]);
        }
    }
};
