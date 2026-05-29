<?php

namespace Database\Seeders\Tenant;

use App\Support\PermissionLabel;
use App\Models\Tenant\PropertyType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(LocationSeeder::class);

        foreach (['Residential', 'Commercial', 'Mixed'] as $propertyTypeName) {
            PropertyType::query()->firstOrCreate([
                'name' => $propertyTypeName,
            ]);
        }

        DB::table('roles')->insertOrIgnore([
            ['name' => 'owner', 'guard_name' => 'api'],
            ['name' => 'manager', 'guard_name' => 'api'],
            ['name' => 'accountant', 'guard_name' => 'api'],
            ['name' => 'cashier', 'guard_name' => 'api'],
            ['name' => 'property_supervisor', 'guard_name' => 'api'],
            ['name' => 'maintenance_officer', 'guard_name' => 'api'],
            ['name' => 'viewer', 'guard_name' => 'api'],
        ]);

        $perms = [
            'locations.view',
            'properties.scope.all',
            'property_types.view','property_types.create','property_types.update','property_types.delete',
            'properties.view','properties.create','properties.update','properties.delete',
            'property_floors.view','property_floors.create','property_floors.update','property_floors.delete',
            'units.view','units.create','units.update',
            'units.delete',
            'customers.view','customers.create','customers.update','customers.delete',
            'customer_contracts.view','customer_contracts.create','customer_contracts.update','customer_contracts.delete',
            'renters.view','renters.create','renters.update',
            'leases.view','leases.create','leases.update',
            'invoices.view','invoices.create',
            'payments.record','reports.view','staff.manage','roles.manage',
            'staff_property_assignments.view','staff_property_assignments.create',
            'staff_property_assignments.update','staff_property_assignments.delete',
        ];
        foreach ($perms as $p) {
            DB::table('permissions')->updateOrInsert([
                'name' => $p,
                'guard_name' => 'api',
            ], [
                'name' => $p,
                'module' => PermissionLabel::moduleFromName($p),
                'guard_name' => 'api',
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        $ownerRoleId = DB::table('roles')->where('name', 'owner')->where('guard_name', 'api')->value('id');
        $managerRoleId = DB::table('roles')->where('name', 'manager')->where('guard_name', 'api')->value('id');
        $viewerRoleId = DB::table('roles')->where('name', 'viewer')->where('guard_name', 'api')->value('id');
        $permissionIds = DB::table('permissions')->where('guard_name', 'api')->pluck('id', 'name');

        foreach ($permissionIds as $permissionId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $ownerRoleId,
            ]);
        }

        foreach ([
            'locations.view',
            'property_types.view',
            'properties.view',
            'property_floors.view',
            'units.view',
            'customers.view',
            'customer_contracts.view',
            'renters.view',
            'leases.view',
            'invoices.view',
            'reports.view',
            'staff_property_assignments.view',
        ] as $name) {
            if (isset($permissionIds[$name])) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionIds[$name],
                    'role_id' => $viewerRoleId,
                ]);
            }
        }

        foreach ([
            'locations.view',
            'property_types.view', 'property_types.create', 'property_types.update',
            'properties.view', 'properties.create', 'properties.update',
            'property_floors.view', 'property_floors.create', 'property_floors.update',
            'units.view', 'units.create', 'units.update',
            'customers.view', 'customers.create', 'customers.update',
            'customer_contracts.view', 'customer_contracts.create', 'customer_contracts.update',
            'renters.view', 'renters.create',
            'leases.view', 'leases.create',
            'reports.view',
            'staff_property_assignments.view', 'staff_property_assignments.create', 'staff_property_assignments.update',
            'staff.manage'
        ] as $name) {
            if (isset($permissionIds[$name])) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionIds[$name],
                    'role_id' => $managerRoleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
