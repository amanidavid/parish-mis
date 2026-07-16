<?php

use App\Support\PermissionLabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $timestamp = now();
        $permissions = [
            'daily_expense_types.view',
            'daily_expense_types.create',
            'daily_expense_types.update',
            'daily_expense_types.delete',
            'daily_property_expenses.view',
            'daily_property_expenses.create',
            'daily_property_expenses.update',
            'daily_property_expenses.delete',
        ];

        foreach ($permissions as $permissionName) {
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

        $managerRoleId = DB::table('roles')
            ->where('name', 'manager')
            ->where('guard_name', 'api')
            ->value('id');

        if (!$managerRoleId) {
            return;
        }

        $managerPermissionIds = DB::table('permissions')
            ->where('guard_name', 'api')
            ->whereIn('name', [
                'daily_expense_types.view',
                'daily_expense_types.create',
                'daily_expense_types.update',
                'daily_property_expenses.view',
                'daily_property_expenses.create',
                'daily_property_expenses.update',
            ])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($managerPermissionIds as $permissionId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $managerRoleId,
            ]);
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

        $managerPermissionIds = DB::table('permissions')
            ->where('guard_name', 'api')
            ->whereIn('name', [
                'daily_expense_types.view',
                'daily_expense_types.create',
                'daily_expense_types.update',
                'daily_property_expenses.view',
                'daily_property_expenses.create',
                'daily_property_expenses.update',
            ])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($managerPermissionIds === []) {
            return;
        }

        DB::table('role_has_permissions')
            ->where('role_id', $managerRoleId)
            ->whereIn('permission_id', $managerPermissionIds)
            ->delete();
    }
};
