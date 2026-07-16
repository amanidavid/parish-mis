<?php

use App\Support\PermissionLabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $timestamp = now();
        $permissions = [
            'property_invoices.view',
            'property_invoices.download',
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

        $roleIds = DB::table('roles')
            ->where('guard_name', 'api')
            ->whereIn('name', ['owner', 'manager'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($roleIds === []) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'api')
            ->whereIn('name', $permissions)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'api')
            ->whereIn('name', [
                'property_invoices.view',
                'property_invoices.download',
            ])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($permissionIds === []) {
            return;
        }

        DB::table('role_has_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    }
};
