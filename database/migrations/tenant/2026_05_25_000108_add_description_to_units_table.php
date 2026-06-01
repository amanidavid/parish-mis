<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('units', 'description')) {
            return;
        }

        $driver = DB::getDriverName();

        Schema::table('units', function (Blueprint $table) use ($driver) {
            $column = $table->text('description')->nullable();

            if ($driver === 'mysql') {
                $column->after('unit_number');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('units', 'description')) {
            return;
        }

        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
