<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('maintenance_jobs')) {
            Schema::create('maintenance_jobs', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
                $table->foreignId('property_floor_id')->nullable()->constrained('property_floors')->restrictOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->enum('status', ['open', 'in_progress', 'closed'])->default('open')->index();
                $table->date('reported_date')->index();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['property_id', 'status']);
                $table->index(['property_id', 'reported_date']);
                $table->index(['property_floor_id', 'reported_date']);
                $table->index(['unit_id', 'reported_date']);
                $table->index(['recorded_by', 'reported_date']);
            });
        }

        if (!Schema::hasTable('maintenance_expenses')) {
            Schema::create('maintenance_expenses', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->foreignId('maintenance_job_id')->constrained('maintenance_jobs')->cascadeOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->decimal('amount', 15, 2);
                $table->date('expense_date')->index();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['maintenance_job_id', 'expense_date']);
                $table->index(['recorded_by', 'expense_date']);
                $table->index(['expense_date', 'id']);
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS maintenance_jobs_title_prefix_idx ON maintenance_jobs (title varchar_pattern_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS maintenance_expenses_title_prefix_idx ON maintenance_expenses (title varchar_pattern_ops)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS maintenance_expenses_title_prefix_idx');
            DB::statement('DROP INDEX IF EXISTS maintenance_jobs_title_prefix_idx');
        }

        Schema::dropIfExists('maintenance_expenses');
        Schema::dropIfExists('maintenance_jobs');
    }
};
