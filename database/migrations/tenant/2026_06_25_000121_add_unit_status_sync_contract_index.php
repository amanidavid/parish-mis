<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        Schema::table('customer_contracts', function (Blueprint $table) {
            $table->index(
                ['unit_id', 'status', 'start_date', 'end_date'],
                'customer_contracts_unit_status_dates_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('customer_contracts')) {
            return;
        }

        Schema::table('customer_contracts', function (Blueprint $table) {
            $table->dropIndex('customer_contracts_unit_status_dates_index');
        });
    }
};
