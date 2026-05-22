<?php

namespace Database\Seeders;

use App\Models\Landlord\BaseUser;
use Database\Seeders\Landlord\BillingProfilesTableSeeder;
use Database\Seeders\Landlord\PlansTableSeeder;
use Database\Seeders\Landlord\SystemAdminSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const DEFAULT_USER_EMAIL = 'test@example.com';
    private const DEFAULT_USER_USERNAME = 'test.user';
    private const DEFAULT_USER_PHONE = '+255700000000';

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PlansTableSeeder::class,
            BillingProfilesTableSeeder::class,
            SystemAdminSeeder::class,
        ]);

        $attributes = [
            'uuid' => (string) Str::uuid(),
            'email' => self::DEFAULT_USER_EMAIL,
            'username' => self::DEFAULT_USER_USERNAME,
            'name' => 'Test User',
            'phone' => self::DEFAULT_USER_PHONE,
            'password' => Hash::make('password'),
            'status' => 'active',
        ];

        $existingUser = BaseUser::query()
            ->where('email', self::DEFAULT_USER_EMAIL)
            ->orWhere('username', self::DEFAULT_USER_USERNAME)
            ->orWhere('phone', self::DEFAULT_USER_PHONE)
            ->first();

        if ($existingUser) {
            $existingUser->forceFill($attributes)->save();

            return;
        }

        BaseUser::query()->create($attributes);
    }
}
