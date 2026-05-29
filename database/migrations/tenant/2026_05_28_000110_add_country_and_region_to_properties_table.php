<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'country_id')) {
                $table->foreignId('country_id')->nullable()->after('type_id')->constrained('countries')->nullOnDelete();
                $table->index(['country_id', 'status']);
            }

            if (!Schema::hasColumn('properties', 'region_id')) {
                $table->foreignId('region_id')->nullable()->after('country_id')->constrained('regions')->nullOnDelete();
                $table->index(['region_id', 'status']);
            }
        });

        DB::statement('
            UPDATE properties
            LEFT JOIN wards ON wards.id = properties.ward_id
            LEFT JOIN districts ON districts.id = COALESCE(properties.district_id, wards.district_id)
            LEFT JOIN regions ON regions.id = districts.region_id
            SET properties.district_id = COALESCE(properties.district_id, wards.district_id),
                properties.region_id = regions.id,
                properties.country_id = regions.country_id
            WHERE properties.district_id IS NOT NULL
               OR properties.ward_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'region_id')) {
                $table->dropConstrainedForeignId('region_id');
            }

            if (Schema::hasColumn('properties', 'country_id')) {
                $table->dropConstrainedForeignId('country_id');
            }
        });
    }
};
