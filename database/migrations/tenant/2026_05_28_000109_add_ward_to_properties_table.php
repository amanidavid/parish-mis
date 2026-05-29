<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'ward_id')) {
                $table->foreignId('ward_id')->nullable()->after('district_id')->constrained('wards')->nullOnDelete();
                $table->index(['ward_id', 'status']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'ward_id')) {
                $table->dropConstrainedForeignId('ward_id');
            }
        });
    }
};
