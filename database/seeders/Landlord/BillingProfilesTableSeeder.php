<?php

namespace Database\Seeders\Landlord;

use App\Models\Landlord\BillingProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BillingProfilesTableSeeder extends Seeder
{
    public function run(): void
    {
        $profile = BillingProfile::query()->firstOrCreate(
            ['name' => 'Default Unit Range Billing'],
            [
                'uuid' => (string) Str::uuid(),
                'description' => 'Legacy default billing profile kept only to support workspace unit-price rules stored in billing_rules.',
                'billing_interval' => 'monthly',
                'trial_days' => 14,
                'grace_days' => 0,
                'currency' => 'TZS',
                'is_default' => true,
                'status' => 'active',
            ]
        );
    }
}
