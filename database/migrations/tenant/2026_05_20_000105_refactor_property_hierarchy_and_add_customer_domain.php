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
        $this->seedFloorStructure();
        $this->refactorUnits();
        $this->dropPropertyBlocks();
        $this->createCustomerTables();
        $this->createDocumentsTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('customer_contracts');
        Schema::dropIfExists('customer_business_details');
        Schema::dropIfExists('customers');

        if (Schema::hasTable('property_blocks')) {
            return;
        }

        Schema::create('property_blocks', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['property_id', 'name']);
            $table->index(['property_id', 'id']);
        });

        if (Schema::hasTable('property_floors')) {
            Schema::table('property_floors', function (Blueprint $table) {
                if (!Schema::hasColumn('property_floors', 'property_block_id')) {
                    $table->foreignId('property_block_id')->nullable()->constrained('property_blocks')->cascadeOnDelete();
                }
            });
        }

        if (Schema::hasTable('units')) {
            Schema::table('units', function (Blueprint $table) {
                if (!Schema::hasColumn('units', 'property_id')) {
                    $table->foreignId('property_id')->nullable()->constrained('properties')->cascadeOnDelete();
                }
            });
        }
    }

    private function seedFloorStructure(): void
    {
        if (!Schema::hasTable('property_floors')) {
            return;
        }

        $driver = DB::getDriverName();

        Schema::table('property_floors', function (Blueprint $table) {
            if (!Schema::hasColumn('property_floors', 'property_id')) {
                $table->foreignId('property_id')->nullable()->constrained('properties')->cascadeOnDelete();
            }

            if (!Schema::hasColumn('property_floors', 'name')) {
                $table->string('name')->nullable();
            }
        });

        if ($driver === 'pgsql') {
            DB::statement('
                UPDATE property_floors
                SET property_id = property_blocks.property_id
                FROM property_blocks
                WHERE property_blocks.id = property_floors.property_block_id
                  AND property_floors.property_id IS NULL
            ');
        } else {
            DB::table('property_floors')
                ->join('property_blocks', 'property_blocks.id', '=', 'property_floors.property_block_id')
                ->whereNull('property_floors.property_id')
                ->update([
                    'property_floors.property_id' => DB::raw('property_blocks.property_id'),
                ]);
        }

        DB::table('property_floors')
            ->whereNull('name')
            ->update([
                'name' => DB::raw("CONCAT('Floor ', floor_number)"),
            ]);

        try {
            Schema::table('property_floors', function (Blueprint $table) {
                $table->dropUnique('property_floors_property_block_id_floor_number_unique');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('property_floors', function (Blueprint $table) {
                $table->unique(['property_id', 'name']);
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('property_floors', function (Blueprint $table) {
                $table->unique(['property_id', 'floor_number']);
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('property_floors', function (Blueprint $table) {
                $table->index(['property_id', 'floor_number']);
            });
        } catch (\Throwable) {
        }
    }

    private function refactorUnits(): void
    {
        if (!Schema::hasTable('units')) {
            return;
        }

        Schema::table('units', function (Blueprint $table) {
            if (!Schema::hasColumn('units', 'property_floor_id')) {
                $table->foreignId('property_floor_id')->nullable()->constrained('property_floors')->cascadeOnDelete();
            }
        });

        $propertiesWithoutFloors = DB::table('units')
            ->leftJoin('property_floors', 'property_floors.property_id', '=', 'units.property_id')
            ->whereNull('property_floors.id')
            ->whereNotNull('units.property_id')
            ->distinct()
            ->pluck('units.property_id');

        $now = now();
        foreach ($propertiesWithoutFloors as $propertyId) {
            DB::table('property_floors')->insert([
                'uuid' => (string) Str::uuid(),
                'property_id' => $propertyId,
                'name' => 'Ground Floor',
                'floor_number' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $defaultFloorByProperty = DB::table('property_floors')
            ->select('property_id', DB::raw('MIN(id) as id'))
            ->groupBy('property_id')
            ->pluck('id', 'property_id');

        foreach ($defaultFloorByProperty as $propertyId => $floorId) {
            DB::table('units')
                ->where('property_id', $propertyId)
                ->whereNull('property_floor_id')
                ->update(['property_floor_id' => $floorId]);
        }

        try {
            Schema::table('units', function (Blueprint $table) {
                $table->dropUnique('units_property_id_unit_number_unique');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('units', function (Blueprint $table) {
                $table->unique(['property_floor_id', 'unit_number']);
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('units', function (Blueprint $table) {
                $table->index(['property_floor_id', 'status']);
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('units', function (Blueprint $table) {
                $table->dropForeign(['property_id']);
            });
        } catch (\Throwable) {
        }

        if (Schema::hasColumn('units', 'property_id')) {
            Schema::table('units', function (Blueprint $table) {
                $table->dropColumn('property_id');
            });
        }
    }

    private function dropPropertyBlocks(): void
    {
        if (!Schema::hasTable('property_floors')) {
            return;
        }

        try {
            Schema::table('property_floors', function (Blueprint $table) {
                $table->dropForeign(['property_block_id']);
            });
        } catch (\Throwable) {
        }

        if (Schema::hasColumn('property_floors', 'property_block_id')) {
            Schema::table('property_floors', function (Blueprint $table) {
                $table->dropColumn('property_block_id');
            });
        }

        Schema::dropIfExists('property_blocks');
    }

    private function createCustomerTables(): void
    {
        if (!Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->enum('customer_type', ['individual', 'business'])->default('individual')->index();
                $table->string('display_name');
                $table->string('email')->nullable();
                $table->string('phone', 30)->nullable();
                $table->enum('status', ['active', 'inactive'])->default('active')->index();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['customer_type', 'display_name']);
                $table->index(['status', 'display_name']);
            });
        }

        if (!Schema::hasTable('customer_business_details')) {
            Schema::create('customer_business_details', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
                $table->string('business_name');
                $table->string('registration_number', 120)->nullable();
                $table->string('tax_identifier', 120)->nullable();
                $table->string('contact_person_name', 150)->nullable();
                $table->string('contact_person_phone', 30)->nullable();
                $table->string('address_line')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('customer_contracts')) {
            Schema::create('customer_contracts', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
                $table->string('contract_number')->unique();
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->decimal('amount', 15, 2);
                $table->char('currency', 3)->default('TZS');
                $table->enum('billing_cycle', ['monthly', 'quarterly', 'semi_annually', 'annually', 'one_time'])->default('monthly');
                $table->enum('status', ['draft', 'active', 'expired', 'terminated', 'renewed'])->default('draft')->index();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['customer_id', 'status']);
                $table->index(['unit_id', 'status']);
                $table->index(['start_date', 'end_date']);
            });
        }
    }

    private function createDocumentsTable(): void
    {
        if (Schema::hasTable('documents')) {
            return;
        }

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->morphs('documentable');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 80)->default('attachment');
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('disk', 80)->default('public');
            $table->string('path');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->enum('status', ['active', 'archived'])->default('active')->index();
            $table->timestamps();

            $table->index(['category', 'status']);
        });
    }
};
