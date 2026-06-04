<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->create('workspace_properties', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->char('property_uuid', 36);
            $table->string('property_name', 160);
            $table->string('property_status', 30)->nullable();
            $table->unsignedInteger('current_registered_units_total')->default(0);
            $table->timestamp('property_created_at')->nullable();
            $table->timestamp('property_updated_at')->nullable();
            $table->timestamp('property_deleted_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'property_uuid'], 'workspace_properties_tenant_property_uuid_unique');
            $table->index(['tenant_id', 'property_name'], 'workspace_properties_tenant_name_idx');
            $table->index(['tenant_id', 'property_status', 'property_deleted_at'], 'workspace_properties_tenant_status_deleted_idx');
            $table->index(['tenant_id', 'current_registered_units_total'], 'workspace_properties_tenant_units_idx');
        });

        Schema::connection('base')->create('property_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workspace_property_id')->constrained('workspace_properties')->cascadeOnDelete();
            $table->foreignId('billing_rule_id')->nullable()->constrained('billing_rules')->nullOnDelete();
            $table->enum('status', ['active', 'expired', 'unsubscribed'])->default('unsubscribed');
            $table->date('current_period_starts_on')->nullable();
            $table->date('current_period_ends_on')->nullable();
            $table->date('last_paid_on')->nullable();
            $table->date('activated_on')->nullable();
            $table->date('expired_on')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['workspace_property_id'], 'property_subscriptions_workspace_property_unique');
            $table->index(['tenant_id', 'status', 'current_period_ends_on'], 'property_subscriptions_tenant_status_ends_idx');
            $table->index(['status', 'current_period_ends_on'], 'property_subscriptions_status_ends_idx');
        });

        Schema::connection('base')->create('property_subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workspace_property_id')->constrained('workspace_properties')->cascadeOnDelete();
            $table->foreignId('property_subscription_id')->constrained('property_subscriptions')->cascadeOnDelete();
            $table->foreignId('billing_rule_id')->nullable()->constrained('billing_rules')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('months_paid');
            $table->unsignedInteger('rule_range_start');
            $table->unsignedInteger('rule_range_end')->nullable();
            $table->unsignedBigInteger('monthly_price_cents');
            $table->unsignedBigInteger('total_amount_cents');
            $table->char('currency', 3)->default('TZS');
            $table->date('payment_date');
            $table->date('coverage_starts_on');
            $table->date('coverage_ends_on');
            $table->string('reference_number', 120)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'payment_date'], 'property_subscription_payments_tenant_payment_date_idx');
            $table->index(['workspace_property_id', 'payment_date'], 'property_subscription_payments_workspace_payment_date_idx');
            $table->index(['property_subscription_id', 'payment_date'], 'property_subscription_payments_subscription_payment_date_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('base')->dropIfExists('property_subscription_payments');
        Schema::connection('base')->dropIfExists('property_subscriptions');
        Schema::connection('base')->dropIfExists('workspace_properties');
    }
};
