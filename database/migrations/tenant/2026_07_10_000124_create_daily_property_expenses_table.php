<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('daily_property_expenses')) {
            Schema::create('daily_property_expenses', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->decimal('amount', 15, 2);
                $table->char('currency', 3)->default('TZS');
                $table->date('expense_date')->index();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['property_id', 'expense_date']);
                $table->index(['property_id', 'created_at', 'id']);
                $table->index(['recorded_by', 'expense_date']);
                $table->index(['created_at', 'id']);
                $table->index(['expense_date', 'id']);
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS daily_property_expenses_title_prefix_idx ON daily_property_expenses (title varchar_pattern_ops)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS daily_property_expenses_title_prefix_idx');
        }

        Schema::dropIfExists('daily_property_expenses');
    }
};
