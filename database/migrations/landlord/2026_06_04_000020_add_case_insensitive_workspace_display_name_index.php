<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection('base')->getDriverName() !== 'pgsql') {
            return;
        }

        DB::connection('base')->statement('DROP INDEX IF EXISTS tenants_display_name_prefix_idx');
        DB::connection('base')->statement(
            'CREATE INDEX IF NOT EXISTS tenants_display_name_lower_prefix_idx ON tenants (LOWER(display_name) varchar_pattern_ops)'
        );
    }

    public function down(): void
    {
        if (DB::connection('base')->getDriverName() !== 'pgsql') {
            return;
        }

        DB::connection('base')->statement('DROP INDEX IF EXISTS tenants_display_name_lower_prefix_idx');
        DB::connection('base')->statement(
            'CREATE INDEX IF NOT EXISTS tenants_display_name_prefix_idx ON tenants (display_name varchar_pattern_ops)'
        );
    }
};
