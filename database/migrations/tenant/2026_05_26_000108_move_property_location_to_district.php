<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();

        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'district_id')) {
                $table->foreignId('district_id')->nullable()->after('type_id')->constrained('districts')->nullOnDelete();
                $table->index(['district_id', 'status']);
            }
        });

        if (Schema::hasColumn('properties', 'ward_id')) {
            if ($driver === 'pgsql') {
                DB::statement('
                    UPDATE properties
                    SET district_id = wards.district_id
                    FROM wards
                    WHERE wards.id = properties.ward_id
                      AND properties.ward_id IS NOT NULL
                      AND properties.district_id IS NULL
                ');
            } else {
                DB::statement('
                    UPDATE properties
                    INNER JOIN wards ON wards.id = properties.ward_id
                    SET properties.district_id = wards.district_id
                    WHERE properties.ward_id IS NOT NULL
                      AND properties.district_id IS NULL
                ');
            }
        }
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'district_id')) {
                $table->dropConstrainedForeignId('district_id');
            }
        });
    }
};
