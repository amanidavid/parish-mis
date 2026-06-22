<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('maintenance_jobs') || Schema::hasColumn('maintenance_jobs', 'status')) {
            return;
        }

        $driver = DB::getDriverName();

        Schema::table('maintenance_jobs', function (Blueprint $table) use ($driver) {
            $column = $table->enum('status', ['open', 'in_progress', 'closed'])
                ->default('open')
                ->index();

            if ($driver === 'mysql') {
                $column->after('description');
            }
        });

        Schema::table('maintenance_jobs', function (Blueprint $table) {
            $table->index(['property_id', 'status'], 'maintenance_jobs_property_id_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('maintenance_jobs') || !Schema::hasColumn('maintenance_jobs', 'status')) {
            return;
        }

        Schema::table('maintenance_jobs', function (Blueprint $table) {
            $table->dropIndex('maintenance_jobs_property_id_status_index');
            $table->dropColumn('status');
        });
    }
};
