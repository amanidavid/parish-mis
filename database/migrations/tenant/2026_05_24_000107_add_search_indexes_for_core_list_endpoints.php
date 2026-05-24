<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('name', 'tenant_users_name_idx');
        });

        Schema::table('property_floors', function (Blueprint $table) {
            $table->index('name', 'property_floors_name_idx');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->index('unit_number', 'units_unit_number_idx');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index('display_name', 'customers_display_name_idx');
            $table->index('email', 'customers_email_idx');
            $table->index('phone', 'customers_phone_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_display_name_idx');
            $table->dropIndex('customers_email_idx');
            $table->dropIndex('customers_phone_idx');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex('units_unit_number_idx');
        });

        Schema::table('property_floors', function (Blueprint $table) {
            $table->dropIndex('property_floors_name_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('tenant_users_name_idx');
        });
    }
};
