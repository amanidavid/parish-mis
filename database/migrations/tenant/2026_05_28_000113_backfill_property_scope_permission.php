<?php

use App\Support\PermissionLabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION_NAME = 'properties.scope.all';
    private const GUARD_NAME = 'api';
    private const FULL_ACCESS_ROLE = 'owner';

    public function up(): void
    {
        $timestamp = now();

        DB::table('permissions')->updateOrInsert(
            [
                'name' => self::PERMISSION_NAME,
                'guard_name' => self::GUARD_NAME,
            ],
            [
                'module' => PermissionLabel::moduleFromName(self::PERMISSION_NAME),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );

        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION_NAME)
            ->where('guard_name', self::GUARD_NAME)
            ->value('id');

        $roleId = DB::table('roles')
            ->where('name', self::FULL_ACCESS_ROLE)
            ->where('guard_name', self::GUARD_NAME)
            ->value('id');

        if ($permissionId && $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION_NAME)
            ->where('guard_name', self::GUARD_NAME)
            ->value('id');

        if ($permissionId) {
            DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->delete();

            DB::table('permissions')
                ->where('id', $permissionId)
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
