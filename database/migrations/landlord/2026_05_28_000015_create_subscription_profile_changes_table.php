<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->create('subscription_profile_changes', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('old_billing_profile_id')->nullable()->constrained('billing_profiles');
            $table->foreignId('new_billing_profile_id')->constrained('billing_profiles');
            $table->enum('change_timing', ['immediate_prorated', 'next_cycle']);
            $table->enum('status', ['pending', 'applied', 'superseded'])->default('pending');
            $table->timestamp('effective_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->unsignedInteger('total_cycle_days')->default(0);
            $table->unsignedInteger('remaining_cycle_days')->default(0);
            $table->integer('current_price_cents')->default(0);
            $table->integer('new_price_cents')->default(0);
            $table->integer('prorated_adjustment_cents')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(
                ['subscription_id', 'status', 'effective_at'],
                'subscription_profile_changes_subscription_status_effective_idx'
            );
            $table->index(
                ['tenant_id', 'status', 'effective_at'],
                'subscription_profile_changes_tenant_status_effective_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::connection('base')->dropIfExists('subscription_profile_changes');
    }
};
