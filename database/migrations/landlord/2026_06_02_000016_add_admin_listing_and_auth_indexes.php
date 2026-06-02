<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::connection('base')->getDriverName();

        if ($driver === 'pgsql') {
            DB::connection('base')->statement('CREATE INDEX IF NOT EXISTS tenants_status_name_idx ON tenants (status, name)');
            DB::connection('base')->statement('CREATE INDEX IF NOT EXISTS tenants_provisioning_status_name_idx ON tenants (provisioning_status, name)');
            DB::connection('base')->statement('CREATE INDEX IF NOT EXISTS tenants_name_prefix_idx ON tenants (name varchar_pattern_ops)');
            DB::connection('base')->statement('CREATE INDEX IF NOT EXISTS tenants_created_id_idx ON tenants (created_at DESC, id DESC)');
            DB::connection('base')->statement('CREATE INDEX IF NOT EXISTS billing_profiles_status_default_name_idx ON billing_profiles (status, is_default DESC, name)');
            DB::connection('base')->statement('CREATE INDEX IF NOT EXISTS billing_profiles_status_interval_default_name_idx ON billing_profiles (status, billing_interval, is_default DESC, name)');
            DB::connection('base')->statement('CREATE INDEX IF NOT EXISTS billing_profiles_interval_default_name_idx ON billing_profiles (billing_interval, is_default DESC, name)');
            DB::connection('base')->statement('CREATE INDEX IF NOT EXISTS billing_profiles_name_prefix_idx ON billing_profiles (name varchar_pattern_ops)');
            DB::connection('base')->statement('CREATE INDEX IF NOT EXISTS users_email_lookup_idx ON users (email)');
            DB::connection('base')->statement('CREATE INDEX IF NOT EXISTS users_phone_lookup_idx ON users (phone)');

            return;
        }

        Schema::connection('base')->table('tenants', function (Blueprint $table) {
            $table->index(['status', 'name'], 'tenants_status_name_idx');
            $table->index(['provisioning_status', 'name'], 'tenants_provisioning_status_name_idx');
            $table->index(['created_at', 'id'], 'tenants_created_id_idx');
        });

        Schema::connection('base')->table('billing_profiles', function (Blueprint $table) {
            $table->index(['status', 'is_default', 'name'], 'billing_profiles_status_default_name_idx');
            $table->index(['status', 'billing_interval', 'is_default', 'name'], 'billing_profiles_status_interval_default_name_idx');
            $table->index(['billing_interval', 'is_default', 'name'], 'billing_profiles_interval_default_name_idx');
        });

        Schema::connection('base')->table('users', function (Blueprint $table) {
            $table->index('email', 'users_email_lookup_idx');
            $table->index('phone', 'users_phone_lookup_idx');
        });
    }

    public function down(): void
    {
        $driver = DB::connection('base')->getDriverName();

        if ($driver === 'pgsql') {
            DB::connection('base')->statement('DROP INDEX IF EXISTS users_phone_lookup_idx');
            DB::connection('base')->statement('DROP INDEX IF EXISTS users_email_lookup_idx');
            DB::connection('base')->statement('DROP INDEX IF EXISTS billing_profiles_name_prefix_idx');
            DB::connection('base')->statement('DROP INDEX IF EXISTS billing_profiles_interval_default_name_idx');
            DB::connection('base')->statement('DROP INDEX IF EXISTS billing_profiles_status_interval_default_name_idx');
            DB::connection('base')->statement('DROP INDEX IF EXISTS billing_profiles_status_default_name_idx');
            DB::connection('base')->statement('DROP INDEX IF EXISTS tenants_created_id_idx');
            DB::connection('base')->statement('DROP INDEX IF EXISTS tenants_name_prefix_idx');
            DB::connection('base')->statement('DROP INDEX IF EXISTS tenants_provisioning_status_name_idx');
            DB::connection('base')->statement('DROP INDEX IF EXISTS tenants_status_name_idx');

            return;
        }

        Schema::connection('base')->table('users', function (Blueprint $table) {
            $table->dropIndex('users_phone_lookup_idx');
            $table->dropIndex('users_email_lookup_idx');
        });

        Schema::connection('base')->table('billing_profiles', function (Blueprint $table) {
            $table->dropIndex('billing_profiles_interval_default_name_idx');
            $table->dropIndex('billing_profiles_status_interval_default_name_idx');
            $table->dropIndex('billing_profiles_status_default_name_idx');
        });

        Schema::connection('base')->table('tenants', function (Blueprint $table) {
            $table->dropIndex('tenants_created_id_idx');
            $table->dropIndex('tenants_provisioning_status_name_idx');
            $table->dropIndex('tenants_status_name_idx');
        });
    }
};
