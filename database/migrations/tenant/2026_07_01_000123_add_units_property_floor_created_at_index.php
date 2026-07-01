<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('units')) {
            return;
        }

        Schema::table('units', function (Blueprint $table) {
            $table->index(
                ['property_floor_id', 'created_at', 'id'],
                'units_property_floor_created_at_id_index'
            );
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('units')) {
            return;
        }

        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex('units_property_floor_created_at_id_index');
        });
    }
};
