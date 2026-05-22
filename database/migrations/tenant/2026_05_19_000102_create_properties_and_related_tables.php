<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('countries')) {
            Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('name')->unique();
            $table->string('code', 10)->nullable()->unique();
            $table->enum('status', ['active','inactive'])->default('active')->index();
            $table->timestamps();

            $table->index(['status', 'name']);
            });
        }

        if (!Schema::hasTable('regions')) {
            Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('name');
            $table->enum('status', ['active','inactive'])->default('active')->index();
            $table->timestamps();

            $table->unique(['country_id', 'name']);
            $table->index(['country_id', 'status']);
            $table->index(['status', 'name']);
            });
        }

        if (!Schema::hasTable('districts')) {
            Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
            $table->string('name');
            $table->enum('status', ['active','inactive'])->default('active')->index();
            $table->timestamps();

            $table->unique(['region_id', 'name']);
            $table->index(['region_id', 'status']);
            $table->index(['status', 'name']);
            });
        }

        if (!Schema::hasTable('wards')) {
            Schema::create('wards', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('district_id')->constrained('districts')->cascadeOnDelete();
            $table->string('name');
            $table->enum('status', ['active','inactive'])->default('active')->index();
            $table->timestamps();

            $table->unique(['district_id', 'name']);
            $table->index(['district_id', 'status']);
            $table->index(['status', 'name']);
            });
        }

        if (!Schema::hasTable('property_types')) {
            Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('name')->unique();
            $table->timestamps();

            $table->index(['name', 'id']);
            });
        }

        if (!Schema::hasTable('properties')) {
            Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('name');
            $table->foreignId('type_id')->nullable()->constrained('property_types')->nullOnDelete();
            $table->foreignId('ward_id')->nullable()->constrained('wards')->nullOnDelete();
            $table->string('address_line')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->enum('status', ['active','inactive'])->default('active')->index();
            $table->timestamps();
            $table->unique(['name']);

            $table->index(['type_id', 'status']);
            $table->index(['ward_id', 'status']);
            $table->index(['status', 'name']);
            });
        }

        if (!Schema::hasTable('property_blocks')) {
            Schema::create('property_blocks', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['property_id','name']);

            $table->index(['property_id', 'id']);
            });
        }

        if (!Schema::hasTable('property_floors')) {
            Schema::create('property_floors', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('property_block_id')->constrained('property_blocks')->cascadeOnDelete();
            $table->unsignedInteger('floor_number');
            $table->timestamps();
            $table->unique(['property_block_id','floor_number']);

            $table->index(['property_block_id', 'id']);
            });
        }

        if (!Schema::hasTable('units')) {
            Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('unit_number');
            $table->enum('status', ['vacant','occupied','maintenance'])->default('vacant')->index();
            $table->timestamps();
            $table->unique(['property_id','unit_number']);

            $table->index(['property_id', 'status']);
            $table->index(['status', 'unit_number']);
            });
        }

        if (!Schema::hasTable('staff_property_assignments')) {
            Schema::create('staff_property_assignments', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id','property_id']);

            $table->index(['property_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_property_assignments');
        Schema::dropIfExists('units');
        Schema::dropIfExists('property_floors');
        Schema::dropIfExists('property_blocks');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('property_types');
        Schema::dropIfExists('wards');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('regions');
        Schema::dropIfExists('countries');
    }
};
