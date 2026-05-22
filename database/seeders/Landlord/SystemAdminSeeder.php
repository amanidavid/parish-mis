<?php

namespace Database\Seeders\Landlord;

use App\Models\Landlord\BaseUser;
use App\Models\Landlord\SystemAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SystemAdminSeeder extends Seeder
{
    private const ADMIN_EMAIL = 'admin@example.com';
    private const ADMIN_USERNAME = 'platform.admin';
    private const ADMIN_PHONE = '+255711000001';
    private const ADMIN_PASSWORD = 'Password@123';
    private const ADMIN_NAME = 'Platform Admin';

    public function run(): void
    {
        DB::connection('base')->transaction(function () {
            $user = BaseUser::query()
                ->where('email', self::ADMIN_EMAIL)
                ->orWhere('username', self::ADMIN_USERNAME)
                ->orWhere('phone', self::ADMIN_PHONE)
                ->first();

            $attributes = [
                'email' => self::ADMIN_EMAIL,
                'username' => self::ADMIN_USERNAME,
                'name' => self::ADMIN_NAME,
                'phone' => self::ADMIN_PHONE,
                'password' => Hash::make(self::ADMIN_PASSWORD),
                'status' => 'active',
                'meta' => [],
            ];

            if ($user) {
                $user->forceFill($attributes)->save();
            } else {
                $user = BaseUser::query()->create([
                    'uuid' => (string) Str::uuid(),
                    ...$attributes,
                ]);
            }

            $admin = SystemAdmin::query()->where('user_id', $user->id)->first();

            if ($admin) {
                $admin->forceFill([
                    'super' => true,
                    'scopes' => [],
                ])->save();

                return;
            }

            SystemAdmin::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'super' => true,
                'scopes' => [],
            ]);
        });
    }
}
