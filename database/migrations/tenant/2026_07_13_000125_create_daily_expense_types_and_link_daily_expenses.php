<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('daily_expense_types')) {
            Schema::create('daily_expense_types', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->string('name');
                $table->timestamps();

                $table->unique('name');
            });
        }

        if (!Schema::hasColumn('daily_property_expenses', 'expense_type_id')) {
            Schema::table('daily_property_expenses', function (Blueprint $table) {
                $table->foreignId('expense_type_id')
                    ->nullable()
                    ->after('property_id')
                    ->constrained('daily_expense_types')
                    ->restrictOnDelete();

                $table->index(['property_id', 'expense_type_id', 'expense_date'], 'daily_prop_exp_property_type_date_idx');
                $table->index(['expense_type_id', 'created_at', 'id'], 'daily_prop_exp_type_created_idx');
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS daily_expense_types_name_prefix_idx ON daily_expense_types (name varchar_pattern_ops)');
        }

        $titles = DB::table('daily_property_expenses')
            ->select('title')
            ->whereNotNull('title')
            ->whereRaw("TRIM(title) <> ''")
            ->distinct()
            ->pluck('title');

        $now = now();

        foreach ($titles as $title) {
            $normalizedTitle = trim((string) $title);

            if ($normalizedTitle === '') {
                continue;
            }

            $exists = DB::table('daily_expense_types')
                ->where('name', $normalizedTitle)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('daily_expense_types')->insert([
                'uuid' => (string) Str::uuid(),
                'name' => $normalizedTitle,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::statement("
            UPDATE daily_property_expenses
            SET expense_type_id = daily_expense_types.id
            FROM daily_expense_types
            WHERE daily_expense_types.name = daily_property_expenses.title
              AND daily_property_expenses.expense_type_id IS NULL
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS daily_expense_types_name_prefix_idx');
        }

        if (Schema::hasColumn('daily_property_expenses', 'expense_type_id')) {
            Schema::table('daily_property_expenses', function (Blueprint $table) {
                $table->dropIndex('daily_prop_exp_property_type_date_idx');
                $table->dropIndex('daily_prop_exp_type_created_idx');
                $table->dropConstrainedForeignId('expense_type_id');
            });
        }

        Schema::dropIfExists('daily_expense_types');
    }
};
