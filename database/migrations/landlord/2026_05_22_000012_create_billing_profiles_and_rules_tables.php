<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('base')->create('billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->enum('billing_interval', ['monthly', 'quarterly', 'annually'])->default('monthly');
            $table->unsignedInteger('trial_days')->default(14);
            $table->unsignedInteger('grace_days')->default(0);
            $table->string('currency', 3)->default('TZS');
            $table->boolean('is_default')->default(false)->index();
            $table->enum('status', ['active', 'inactive'])->default('active')->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::connection('base')->create('billing_rules', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('billing_profile_id')->constrained('billing_profiles')->cascadeOnDelete();
            $table->unsignedInteger('range_start');
            $table->unsignedInteger('range_end')->nullable();
            $table->unsignedBigInteger('price_cents');
            $table->string('currency', 3)->default('TZS');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active')->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['billing_profile_id', 'status', 'effective_from'], 'billing_rules_profile_status_effective_idx');
            $table->index(['billing_profile_id', 'range_start', 'range_end'], 'billing_rules_profile_range_idx');
        });

        Schema::connection('base')->table('subscriptions', function (Blueprint $table) {
            $table->foreignId('billing_profile_id')
                ->nullable()
                ->after('plan_id')
                ->constrained('billing_profiles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('base')->table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_profile_id');
        });

        Schema::connection('base')->dropIfExists('billing_rules');
        Schema::connection('base')->dropIfExists('billing_profiles');
    }
};
