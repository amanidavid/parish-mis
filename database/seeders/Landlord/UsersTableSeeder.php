<?php

namespace Database\Seeders\Landlord;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    public function run(): void
    {
        DB::connection('base')->table('users')->insertOrIgnore([
            [
                'uuid' => (string) Str::uuid(),
                'username' => 'demo_owner',
                'name' => 'Demo Owner',
                'phone' => '+255700000000',
                'email' => 'owner@example.com',
                'password' => Hash::make('Password@123'),
                'status' => 'active',
                'meta' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
