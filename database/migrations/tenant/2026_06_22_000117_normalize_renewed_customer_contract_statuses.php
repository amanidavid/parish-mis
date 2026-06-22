<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('customer_contracts')) {
            return;
        }

        DB::table('customer_contracts')
            ->where('status', 'renewed')
            ->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left blank because the previous renewed state is normalized into active.
    }
};
