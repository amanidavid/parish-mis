<?php

namespace Database\Seeders\Landlord;

use App\Models\Landlord\BillingProfile;
use App\Models\Landlord\BillingRule;
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
                'description' => 'Default billing profile based on registered units per property.',
                'billing_interval' => 'monthly',
                'trial_days' => 14,
                'grace_days' => 0,
                'currency' => 'TZS',
                'is_default' => true,
                'status' => 'active',
            ]
        );

        $rules = [
            ['range_start' => 1, 'range_end' => 6, 'price_cents' => 5000, 'sort_order' => 10],
            ['range_start' => 7, 'range_end' => 12, 'price_cents' => 9000, 'sort_order' => 20],
            ['range_start' => 13, 'range_end' => 20, 'price_cents' => 14000, 'sort_order' => 30],
            ['range_start' => 21, 'range_end' => null, 'price_cents' => 20000, 'sort_order' => 40],
        ];

        foreach ($rules as $rule) {
            BillingRule::query()->firstOrCreate(
                [
                    'billing_profile_id' => $profile->id,
                    'range_start' => $rule['range_start'],
                    'range_end' => $rule['range_end'],
                    'effective_from' => now()->toDateString(),
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'price_cents' => $rule['price_cents'],
                    'currency' => 'TZS',
                    'effective_to' => null,
                    'sort_order' => $rule['sort_order'],
                    'status' => 'active',
                ]
            );
        }
    }
}
