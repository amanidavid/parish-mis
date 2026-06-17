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
        if (!Schema::hasTable('customers') || !Schema::hasTable('properties')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'property_id')) {
                $table->foreignId('property_id')->nullable()->constrained('properties')->restrictOnDelete();
            }
        });

        $this->backfillCustomerPropertyOwnership();
        $this->setColumnNotNullable('customers', 'property_id');
        $this->addIndexes();
    }

    public function down(): void
    {
        if (!Schema::hasTable('customers')) {
            return;
        }

        $driver = DB::getDriverName();

        $this->dropIndexIfExists('customers', 'customers_property_display_name_index', $driver);
        $this->dropIndexIfExists('customers', 'customers_property_email_index', $driver);
        $this->dropIndexIfExists('customers', 'customers_property_phone_index', $driver);
        $this->dropIndexIfExists('customers', 'customers_property_status_display_name_index', $driver);

        if (Schema::hasColumn('customers', 'property_id')) {
            $this->setColumnNullable('customers', 'property_id');

            Schema::table('customers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('property_id');
            });
        }
    }

    private function backfillCustomerPropertyOwnership(): void
    {
        $singlePropertyId = DB::table('properties')->count() === 1
            ? DB::table('properties')->value('id')
            : null;

        $customers = DB::table('customers')
            ->select('id')
            ->orderBy('id')
            ->get();

        foreach ($customers as $customerRow) {
            $propertyIds = DB::table('customer_contracts')
                ->join('units', 'units.id', '=', 'customer_contracts.unit_id')
                ->join('property_floors', 'property_floors.id', '=', 'units.property_floor_id')
                ->where('customer_contracts.customer_id', $customerRow->id)
                ->distinct()
                ->pluck('property_floors.property_id')
                ->map(fn ($value) => (int) $value)
                ->values()
                ->all();

            if (count($propertyIds) === 1) {
                DB::table('customers')
                    ->where('id', $customerRow->id)
                    ->update(['property_id' => $propertyIds[0]]);

                continue;
            }

            if (count($propertyIds) > 1) {
                $this->splitCustomerAcrossProperties((int) $customerRow->id, $propertyIds);

                continue;
            }

            if ($singlePropertyId !== null) {
                DB::table('customers')
                    ->where('id', $customerRow->id)
                    ->update(['property_id' => $singlePropertyId]);

                continue;
            }

            throw new \RuntimeException(sprintf(
                'Customer ID %d cannot be mapped to a property automatically. Assign a property or add a contract before running this migration.',
                $customerRow->id
            ));
        }
    }

    private function splitCustomerAcrossProperties(int $customerId, array $propertyIds): void
    {
        $baseCustomer = DB::table('customers')->where('id', $customerId)->first();
        $businessDetail = DB::table('customer_business_details')->where('customer_id', $customerId)->first();

        $primaryPropertyId = (int) array_shift($propertyIds);

        DB::table('customers')
            ->where('id', $customerId)
            ->update(['property_id' => $primaryPropertyId]);

        foreach ($propertyIds as $propertyId) {
            $newCustomerId = DB::table('customers')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'property_id' => $propertyId,
                'customer_type' => $baseCustomer->customer_type,
                'display_name' => $baseCustomer->display_name,
                'email' => $baseCustomer->email,
                'phone' => $baseCustomer->phone,
                'status' => $baseCustomer->status,
                'notes' => $baseCustomer->notes,
                'created_at' => $baseCustomer->created_at,
                'updated_at' => $baseCustomer->updated_at,
            ]);

            if ($businessDetail) {
                DB::table('customer_business_details')->insert([
                    'uuid' => (string) Str::uuid(),
                    'customer_id' => $newCustomerId,
                    'business_name' => $businessDetail->business_name,
                    'registration_number' => $businessDetail->registration_number,
                    'tax_identifier' => $businessDetail->tax_identifier,
                    'contact_person_name' => $businessDetail->contact_person_name,
                    'contact_person_phone' => $businessDetail->contact_person_phone,
                    'address_line' => $businessDetail->address_line,
                    'created_at' => $businessDetail->created_at,
                    'updated_at' => $businessDetail->updated_at,
                ]);
            }

            $contractIds = DB::table('customer_contracts')
                ->join('units', 'units.id', '=', 'customer_contracts.unit_id')
                ->join('property_floors', 'property_floors.id', '=', 'units.property_floor_id')
                ->where('customer_contracts.customer_id', $customerId)
                ->where('property_floors.property_id', $propertyId)
                ->pluck('customer_contracts.id');

            DB::table('customer_contracts')
                ->whereIn('id', $contractIds)
                ->update(['customer_id' => $newCustomerId]);
        }
    }

    private function addIndexes(): void
    {
        $driver = DB::getDriverName();

        if (!$this->hasIndex('customers', 'customers_property_display_name_index', $driver)) {
            Schema::table('customers', function (Blueprint $table) {
                $table->index(['property_id', 'display_name'], 'customers_property_display_name_index');
            });
        }

        if (!$this->hasIndex('customers', 'customers_property_email_index', $driver)) {
            Schema::table('customers', function (Blueprint $table) {
                $table->index(['property_id', 'email'], 'customers_property_email_index');
            });
        }

        if (!$this->hasIndex('customers', 'customers_property_phone_index', $driver)) {
            Schema::table('customers', function (Blueprint $table) {
                $table->index(['property_id', 'phone'], 'customers_property_phone_index');
            });
        }

        if (!$this->hasIndex('customers', 'customers_property_status_display_name_index', $driver)) {
            Schema::table('customers', function (Blueprint $table) {
                $table->index(['property_id', 'status', 'display_name'], 'customers_property_status_display_name_index');
            });
        }
    }

    private function setColumnNotNullable(string $table, string $column): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf('ALTER TABLE "%s" ALTER COLUMN "%s" SET NOT NULL', $table, $column));

            return;
        }

        DB::statement(sprintf('ALTER TABLE `%s` MODIFY `%s` BIGINT UNSIGNED NOT NULL', $table, $column));
    }

    private function setColumnNullable(string $table, string $column): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf('ALTER TABLE "%s" ALTER COLUMN "%s" DROP NOT NULL', $table, $column));

            return;
        }

        DB::statement(sprintf('ALTER TABLE `%s` MODIFY `%s` BIGINT UNSIGNED NULL', $table, $column));
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

    private function dropIndexIfExists(string $table, string $index, string $driver): void
    {
        if (!$this->hasIndex($table, $index, $driver)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($index) {
            $table->dropIndex($index);
        });
    }
};
