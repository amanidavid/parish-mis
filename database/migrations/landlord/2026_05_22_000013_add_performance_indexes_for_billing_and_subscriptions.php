<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('base')->table('subscriptions', function (Blueprint $table) {
            $table->index(['tenant_id', 'id'], 'subscriptions_tenant_latest_idx');
            $table->index(['tenant_id', 'status', 'id'], 'subscriptions_tenant_status_latest_idx');
        });

        Schema::connection('base')->table('billing_profiles', function (Blueprint $table) {
            $table->index(['status', 'is_default'], 'billing_profiles_status_default_idx');
        });

        Schema::connection('base')->table('billing_rules', function (Blueprint $table) {
            $table->index(
                ['billing_profile_id', 'status', 'range_start', 'range_end', 'effective_from', 'effective_to'],
                'billing_rules_lookup_idx'
            );
        });

        Schema::connection('base')->table('plans', function (Blueprint $table) {
            $table->index(['status', 'name', 'price_cents'], 'plans_status_name_price_idx');
            $table->index(['status', 'price_cents', 'properties_included'], 'plans_status_price_properties_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('base')->table('plans', function (Blueprint $table) {
            $table->dropIndex('plans_status_name_price_idx');
            $table->dropIndex('plans_status_price_properties_idx');
        });

        Schema::connection('base')->table('billing_rules', function (Blueprint $table) {
            $table->dropIndex('billing_rules_lookup_idx');
        });

        Schema::connection('base')->table('billing_profiles', function (Blueprint $table) {
            $table->dropIndex('billing_profiles_status_default_idx');
        });

        Schema::connection('base')->table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_tenant_latest_idx');
            $table->dropIndex('subscriptions_tenant_status_latest_idx');
        });
    }
};
