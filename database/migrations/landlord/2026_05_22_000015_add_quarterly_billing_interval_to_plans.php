<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::connection('base')->getDriverName();

        if ($driver === 'pgsql') {
            DB::connection('base')->statement('ALTER TABLE plans DROP CONSTRAINT IF EXISTS plans_billing_interval_check');
            DB::connection('base')->statement(
                "ALTER TABLE plans ADD CONSTRAINT plans_billing_interval_check CHECK (billing_interval IN ('monthly', 'quarterly', 'annual'))"
            );

            return;
        }

        DB::connection('base')->statement(
            "ALTER TABLE plans MODIFY billing_interval ENUM('monthly','quarterly','annual') NOT NULL"
        );
    }

    public function down(): void
    {
        $driver = DB::connection('base')->getDriverName();

        if ($driver === 'pgsql') {
            DB::connection('base')->statement('ALTER TABLE plans DROP CONSTRAINT IF EXISTS plans_billing_interval_check');
            DB::connection('base')->statement(
                "ALTER TABLE plans ADD CONSTRAINT plans_billing_interval_check CHECK (billing_interval IN ('monthly', 'annual'))"
            );

            return;
        }

        DB::connection('base')->statement(
            "ALTER TABLE plans MODIFY billing_interval ENUM('monthly','annual') NOT NULL"
        );
    }
};
