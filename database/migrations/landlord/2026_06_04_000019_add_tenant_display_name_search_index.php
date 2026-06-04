<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection('base')->getDriverName() === 'pgsql') {
            DB::connection('base')->statement(
                'CREATE INDEX IF NOT EXISTS tenants_display_name_prefix_idx ON tenants (display_name varchar_pattern_ops)'
            );

            return;
        }

        Schema::connection('base')->table('tenants', function (Blueprint $table) {
            $table->index('display_name', 'tenants_display_name_prefix_idx');
        });
    }

    public function down(): void
    {
        if (DB::connection('base')->getDriverName() === 'pgsql') {
            DB::connection('base')->statement('DROP INDEX IF EXISTS tenants_display_name_prefix_idx');

            return;
        }

        Schema::connection('base')->table('tenants', function (Blueprint $table) {
            $table->dropIndex('tenants_display_name_prefix_idx');
        });
    }
};
