<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->create('subscription_usage_baselines', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('billing_profile_id')->nullable()->constrained('billing_profiles')->nullOnDelete();
            $table->timestamp('period_starts_at');
            $table->timestamp('period_ends_at');
            $table->timestamp('accounted_at');
            $table->unsignedInteger('total_properties')->default(0);
            $table->unsignedInteger('registered_units_total')->default(0);
            $table->bigInteger('total_price_cents')->default(0);
            $table->json('frequencies');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['subscription_id', 'period_starts_at'],
                'subscription_usage_baselines_subscription_period_unique'
            );
            $table->index(
                ['tenant_id', 'period_starts_at'],
                'subscription_usage_baselines_tenant_period_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::connection('base')->dropIfExists('subscription_usage_baselines');
    }
};
