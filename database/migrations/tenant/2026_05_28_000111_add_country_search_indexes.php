<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();

        Schema::table('countries', function (Blueprint $table) {
            if (!Schema::hasColumn('countries', 'dial_code_search')) {
                $table->string('dial_code_search', 20)->nullable();
            }
        });

        DB::statement("
            UPDATE countries
            SET dial_code_search = NULLIF(REGEXP_REPLACE(COALESCE(dial_code, ''), '[^0-9]', ''), '')
        ");

        if (!$this->hasIndex('countries', 'countries_status_code_index', $driver)) {
            Schema::table('countries', function (Blueprint $table) {
                $table->index(['status', 'code'], 'countries_status_code_index');
            });
        }

        if (!$this->hasIndex('countries', 'countries_status_dial_code_search_index', $driver)) {
            Schema::table('countries', function (Blueprint $table) {
                $table->index(['status', 'dial_code_search'], 'countries_status_dial_code_search_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            if (Schema::hasColumn('countries', 'dial_code_search')) {
                if ($this->hasIndex('countries', 'countries_status_dial_code_search_index')) {
                    $table->dropIndex('countries_status_dial_code_search_index');
                }

                if ($this->hasIndex('countries', 'countries_status_code_index')) {
                    $table->dropIndex('countries_status_code_index');
                }

                $table->dropColumn('dial_code_search');
            }
        });
    }

    private function hasIndex(string $tableName, string $indexName, string $driver): bool
    {
        if ($driver === 'pgsql') {
            return DB::table('pg_indexes')
                ->where('schemaname', DB::getConfig('search_path') ?: 'public')
                ->where('tablename', $tableName)
                ->where('indexname', $indexName)
                ->exists();
        }

        $database = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $tableName)
            ->where('index_name', $indexName)
            ->exists();
    }
};
