<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customers')) {
            return;
        }

        $driver = DB::getDriverName();

        $this->dropForeignIfExists('customers', 'customers_property_id_foreign', $driver);
        $this->dropForeignIfExists('customers', 'customers_unit_id_foreign', $driver);

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

        if (!$this->hasIndex('customers', 'customers_customer_type_display_name_index', $driver)) {
            Schema::table('customers', function (Blueprint $table) {
                $table->index(['customer_type', 'display_name'], 'customers_customer_type_display_name_index');
            });
        }

        if (!$this->hasIndex('customers', 'customers_status_display_name_index', $driver)) {
            Schema::table('customers', function (Blueprint $table) {
                $table->index(['status', 'display_name'], 'customers_status_display_name_index');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('customers')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'property_id')) {
                $table->foreignId('property_id')->nullable()->constrained('properties')->cascadeOnDelete();
            }
        });
    }

    private function dropForeignIfExists(string $table, string $constraint, string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::statement(sprintf('ALTER TABLE "%s" DROP CONSTRAINT IF EXISTS "%s"', $table, $constraint));

            return;
        }

        $exists = DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();

        if ($exists) {
            DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $constraint));
        }
    }

    private function hasIndex(string $table, string $index, string $driver): bool
    {
        if ($driver === 'pgsql') {
            return DB::table('pg_indexes')
                ->where('schemaname', DB::getConfig('search_path') ?: 'public')
                ->where('tablename', $table)
                ->where('indexname', $index)
                ->exists();
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
