<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->table('billing_rules', function (Blueprint $table) {
            $table->dropIndex('billing_rules_tenant_status_effective_idx');
            $table->dropConstrainedForeignId('tenant_id');
            $table->index(['status', 'effective_from', 'effective_to'], 'billing_rules_status_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('base')->table('billing_rules', function (Blueprint $table) {
            $table->dropIndex('billing_rules_status_effective_idx');
            $table->foreignId('tenant_id')->nullable()->after('uuid')->constrained('tenants')->nullOnDelete();
            $table->index(['tenant_id', 'status', 'effective_from', 'effective_to'], 'billing_rules_tenant_status_effective_idx');
        });
    }
};
