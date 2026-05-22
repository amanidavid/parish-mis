<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customers')) {
            return;
        }

        try {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropForeign(['property_id']);
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropForeign(['unit_id']);
            });
        } catch (\Throwable) {
        }

        if (Schema::hasColumn('customers', 'property_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('property_id');
            });
        }

        if (Schema::hasColumn('customers', 'unit_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('unit_id');
            });
        }

        try {
            Schema::table('customers', function (Blueprint $table) {
                $table->index(['customer_type', 'display_name']);
                $table->index(['status', 'display_name']);
            });
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('customers')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'property_id')) {
                $table->foreignId('property_id')->nullable()->after('uuid')->constrained('properties')->cascadeOnDelete();
            }
        });
    }
};
