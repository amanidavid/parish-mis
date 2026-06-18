<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customer_contracts')) {
            return;
        }

        Schema::table('customer_contracts', function (Blueprint $table) {
            $table->index(['unit_id', 'start_date'], 'customer_contracts_unit_id_start_date_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('customer_contracts')) {
            return;
        }

        Schema::table('customer_contracts', function (Blueprint $table) {
            $table->dropIndex('customer_contracts_unit_id_start_date_index');
        });
    }
};
