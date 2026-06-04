<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->create('subscription_usage_adjustments', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('billing_profile_id')->nullable()->constrained('billing_profiles')->nullOnDelete();
            $table->enum('reason', ['usage_change'])->default('usage_change');
            $table->enum('status', ['pending', 'applied', 'waived', 'superseded'])->default('pending');
            $table->enum('adjustment_type', ['charge', 'credit', 'none'])->default('none');
            $table->timestamp('effective_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('waived_at')->nullable();
            $table->timestamp('period_starts_at');
            $table->timestamp('period_ends_at');
            $table->unsignedInteger('total_cycle_days')->default(0);
            $table->unsignedInteger('remaining_cycle_days')->default(0);
            $table->unsignedInteger('baseline_properties_count')->default(0);
            $table->unsignedInteger('current_properties_count')->default(0);
            $table->unsignedInteger('baseline_registered_units_total')->default(0);
            $table->unsignedInteger('current_registered_units_total')->default(0);
            $table->bigInteger('baseline_amount_cents')->default(0);
            $table->bigInteger('current_amount_cents')->default(0);
            $table->bigInteger('delta_price_cents')->default(0);
            $table->bigInteger('prorated_adjustment_cents')->default(0);
            $table->json('baseline_frequencies');
            $table->json('current_frequencies');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(
                ['subscription_id', 'status', 'period_starts_at'],
                'subscription_usage_adjustments_subscription_status_period_idx'
            );
            $table->index(
                ['tenant_id', 'created_at'],
                'subscription_usage_adjustments_tenant_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::connection('base')->dropIfExists('subscription_usage_adjustments');
    }
};
