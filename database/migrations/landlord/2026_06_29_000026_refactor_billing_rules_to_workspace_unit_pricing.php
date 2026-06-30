<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->table('billing_rules', function (Blueprint $table) {
            $table->dropIndex('billing_rules_profile_range_idx');
            $table->dropIndex('billing_rules_lookup_idx');
            $table->foreignId('tenant_id')->nullable()->after('uuid')->constrained('tenants')->nullOnDelete();
        });

        Schema::connection('base')->table('billing_rules', function (Blueprint $table) {
            $table->renameColumn('price_cents', 'unit_price_cents');
            $table->dropColumn(['range_start', 'range_end', 'sort_order']);
            $table->index(['tenant_id', 'status', 'effective_from', 'effective_to'], 'billing_rules_tenant_status_effective_idx');
        });

        Schema::connection('base')->table('property_subscription_payments', function (Blueprint $table) {
            $table->unsignedInteger('unit_count_at_payment')->default(0)->after('months_paid');
            $table->unsignedBigInteger('unit_price_cents_at_payment')->default(0)->after('unit_count_at_payment');
            $table->dropColumn(['rule_range_start', 'rule_range_end']);
        });
    }

    public function down(): void
    {
        Schema::connection('base')->table('property_subscription_payments', function (Blueprint $table) {
            $table->unsignedInteger('rule_range_start')->default(0)->after('months_paid');
            $table->unsignedInteger('rule_range_end')->nullable()->after('rule_range_start');
            $table->dropColumn(['unit_count_at_payment', 'unit_price_cents_at_payment']);
        });

        Schema::connection('base')->table('billing_rules', function (Blueprint $table) {
            $table->dropIndex('billing_rules_tenant_status_effective_idx');
            $table->unsignedInteger('range_start')->default(1)->after('billing_profile_id');
            $table->unsignedInteger('range_end')->nullable()->after('range_start');
            $table->unsignedInteger('sort_order')->default(0)->after('effective_to');
            $table->renameColumn('unit_price_cents', 'price_cents');
        });

        Schema::connection('base')->table('billing_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->index(['billing_profile_id', 'range_start', 'range_end'], 'billing_rules_profile_range_idx');
            $table->index(
                ['billing_profile_id', 'status', 'range_start', 'range_end', 'effective_from', 'effective_to'],
                'billing_rules_lookup_idx'
            );
        });
    }
};
