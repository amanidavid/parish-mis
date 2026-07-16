<?php

use App\Support\PermissionLabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $timestamp = now();
        $legacyPermissionNames = [
            'invoices.view',
            'invoices.create',
        ];
        $currentPermissionNames = [
            'property_invoices.view',
            'property_invoices.download',
        ];

        foreach ($currentPermissionNames as $permissionName) {
            DB::table('permissions')->updateOrInsert(
                [
                    'name' => $permissionName,
                    'guard_name' => 'api',
                ],
                [
                    'name' => $permissionName,
                    'module' => PermissionLabel::moduleFromName($permissionName),
                    'guard_name' => 'api',
                    'updated_at' => $timestamp,
                    'created_at' => $timestamp,
                ]
            );
        }

        $ownerAndManagerRoleIds = DB::table('roles')
            ->where('guard_name', 'api')
            ->whereIn('name', ['owner', 'manager'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $currentPermissionIds = DB::table('permissions')
            ->where('guard_name', 'api')
            ->whereIn('name', $currentPermissionNames)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($ownerAndManagerRoleIds as $roleId) {
            foreach ($currentPermissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        $legacyPermissionIds = DB::table('permissions')
            ->where('guard_name', 'api')
            ->whereIn('name', $legacyPermissionNames)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($legacyPermissionIds === []) {
            return;
        }

        DB::table('role_has_permissions')
            ->whereIn('permission_id', $legacyPermissionIds)
            ->delete();

        DB::table('model_has_permissions')
            ->where('model_type', \App\Models\Tenant\User::class)
            ->whereIn('permission_id', $legacyPermissionIds)
            ->delete();

        DB::table('permissions')
            ->whereIn('id', $legacyPermissionIds)
            ->delete();
    }

    public function down(): void
    {
        $timestamp = now();
        $legacyPermissionNames = [
            'invoices.view',
            'invoices.create',
        ];

        foreach ($legacyPermissionNames as $permissionName) {
            DB::table('permissions')->updateOrInsert(
                [
                    'name' => $permissionName,
                    'guard_name' => 'api',
                ],
                [
                    'name' => $permissionName,
                    'module' => PermissionLabel::moduleFromName($permissionName),
                    'guard_name' => 'api',
                    'updated_at' => $timestamp,
                    'created_at' => $timestamp,
                ]
            );
        }
    }
};
