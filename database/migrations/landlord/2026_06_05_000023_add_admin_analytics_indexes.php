<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->table('property_subscription_payments', function (Blueprint $table) {
            // Supports revenue date filters plus distinct property/workspace counts for billing-rule ranking.
            $table->index(
                ['payment_date', 'billing_rule_id', 'workspace_property_id', 'tenant_id'],
                'property_subscription_payments_payment_rule_property_tenant_idx'
            );
            // Supports historical coverage-window lookups used by the subscription status trend reconstruction.
            $table->index(
                ['coverage_starts_on', 'coverage_ends_on', 'workspace_property_id'],
                'property_subscription_payments_coverage_window_idx'
            );
        });

        Schema::connection('base')->table('workspace_properties', function (Blueprint $table) {
            // Supports the primary registry timestamp copied from tenant properties.
            $table->index('property_created_at', 'workspace_properties_property_created_at_idx');
            // Supports the fallback branch for older rows that do not yet have property_created_at.
            $table->index('created_at', 'workspace_properties_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('base')->table('workspace_properties', function (Blueprint $table) {
            $table->dropIndex('workspace_properties_property_created_at_idx');
            $table->dropIndex('workspace_properties_created_at_idx');
        });

        Schema::connection('base')->table('property_subscription_payments', function (Blueprint $table) {
            $table->dropIndex('property_subscription_payments_payment_rule_property_tenant_idx');
            $table->dropIndex('property_subscription_payments_coverage_window_idx');
        });
    }
};
