<?php

namespace Database\Seeders\Landlord;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantsTableSeeder extends Seeder
{
    public function run(): void
    {
        DB::connection('base')->table('tenants')->insertOrIgnore([
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'demo',
                'display_name' => 'Demo Tenant',
                'database' => 'demo_tenant',
                'status' => 'active',
                'meta' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
