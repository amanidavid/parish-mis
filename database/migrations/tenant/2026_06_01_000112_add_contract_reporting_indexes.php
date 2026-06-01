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
            $table->index(['status', 'end_date'], 'customer_contracts_status_end_date_index');
            $table->index(['status', 'start_date'], 'customer_contracts_status_start_date_index');
            $table->index(['billing_cycle', 'status'], 'customer_contracts_billing_cycle_status_index');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index(['display_name'], 'customers_display_name_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('customer_contracts')) {
            return;
        }

        Schema::table('customer_contracts', function (Blueprint $table) {
            $table->dropIndex('customer_contracts_status_end_date_index');
            $table->dropIndex('customer_contracts_status_start_date_index');
            $table->dropIndex('customer_contracts_billing_cycle_status_index');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_display_name_index');
        });
    }
};
