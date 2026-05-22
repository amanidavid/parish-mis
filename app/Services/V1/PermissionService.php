<?php

namespace App\Services\V1;

use App\Support\PermissionLabel;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Permission;

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

        DB::table('permissions')
            ->where('id', $permission->id)
            ->update([
                'module' => $module,
                'updated_at' => now(),
            ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Permission::query()->findOrFail($permission->id);
    }

    public function backfillModules(): void
    {
        Permission::query()->select(['id', 'name'])->chunkById(100, function ($permissions) {
            foreach ($permissions as $permission) {
                DB::table('permissions')
                    ->where('id', $permission->id)
                    ->update([
                        'module' => PermissionLabel::moduleFromName($permission->name),
                        'updated_at' => now(),
                    ]);
            }
        });
    }
}
