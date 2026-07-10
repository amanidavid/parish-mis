<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('countries', 'currency_code')) {
            Schema::table('countries', function (Blueprint $table) {
                $table->char('currency_code', 3)->nullable()->after('code');
            });
        }

        if (!Schema::hasColumn('properties', 'currency')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->char('currency', 3)->nullable()->after('postal_code');
            });
        }

        $currencyMap = require database_path('seeders/Tenant/data/locations/country_currency_map.php');
        $driver = DB::getDriverName();

        if ($currencyMap !== []) {
            $cases = [];
            foreach ($currencyMap as $code => $currencyCode) {
                $code = str_replace("'", "''", (string) $code);
                $currencyCode = str_replace("'", "''", (string) $currencyCode);
                $cases[] = "WHEN '{$code}' THEN '{$currencyCode}'";
            }

            $caseSql = implode(' ', $cases);
            DB::statement("UPDATE countries SET currency_code = CASE UPPER(code) {$caseSql} ELSE currency_code END");
        }

        DB::statement("
            UPDATE properties
            SET currency = COALESCE(
                (
                    SELECT units.rent_currency
                    FROM units
                    INNER JOIN property_floors ON property_floors.id = units.property_floor_id
                    WHERE property_floors.property_id = properties.id
                      AND units.rent_currency IS NOT NULL
                    ORDER BY units.id
                    LIMIT 1
                ),
                (
                    SELECT customer_contracts.currency
                    FROM customer_contracts
                    INNER JOIN units ON units.id = customer_contracts.unit_id
                    INNER JOIN property_floors ON property_floors.id = units.property_floor_id
                    WHERE property_floors.property_id = properties.id
                      AND customer_contracts.currency IS NOT NULL
                    ORDER BY customer_contracts.id
                    LIMIT 1
                ),
                (
                    SELECT countries.currency_code
                    FROM countries
                    WHERE countries.id = properties.country_id
                    LIMIT 1
                ),
                'TZS'
            )
            WHERE properties.currency IS NULL
        ");

        DB::statement("
            UPDATE units
            SET rent_currency = COALESCE(
                (
                    SELECT properties.currency
                    FROM property_floors
                    INNER JOIN properties ON properties.id = property_floors.property_id
                    WHERE property_floors.id = units.property_floor_id
                    LIMIT 1
                ),
                rent_currency,
                'TZS'
            )
        ");

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE countries ALTER COLUMN currency_code SET DEFAULT 'TZS'");
            DB::statement("ALTER TABLE properties ALTER COLUMN currency SET DEFAULT 'TZS'");
        }

    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'currency')) {
                $table->dropColumn('currency');
            }
        });

        Schema::table('countries', function (Blueprint $table) {
            if (Schema::hasColumn('countries', 'currency_code')) {
                $table->dropColumn('currency_code');
            }
        });
    }
};
