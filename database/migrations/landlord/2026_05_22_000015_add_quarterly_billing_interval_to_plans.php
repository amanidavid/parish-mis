<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::connection('base')->statement(
            "ALTER TABLE plans MODIFY billing_interval ENUM('monthly','quarterly','annual') NOT NULL"
        );
    }

    public function down(): void
    {
        DB::connection('base')->statement(
            "ALTER TABLE plans MODIFY billing_interval ENUM('monthly','annual') NOT NULL"
        );
    }
};
