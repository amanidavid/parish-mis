<?php

namespace Database\Seeders\Landlord;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlansTableSeeder extends Seeder
{
    public function run(): void
    {
        DB::connection('base')->table('plans')->insertOrIgnore([
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'starter',
                'price_cents' => 0,
                'price_per_property_cents' => 0,
                'properties_included' => 1,
                'trial_days' => 14,
                'billing_interval' => 'monthly',
                'features' => json_encode(['units_limit' => 50]),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'pro',
                'price_cents' => 1999,
                'price_per_property_cents' => 99,
                'properties_included' => 10,
                'trial_days' => 14,
                'billing_interval' => 'monthly',
                'features' => json_encode(['units_limit' => 500]),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
