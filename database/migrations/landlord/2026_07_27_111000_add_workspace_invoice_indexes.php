<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->table('property_invoices', function (Blueprint $table) {
            $table->index(['tenant_id', 'issue_date'], 'property_invoices_tenant_issue_idx');
            $table->index(['tenant_id', 'due_date'], 'property_invoices_tenant_due_idx');
            $table->index(['tenant_id', 'created_at'], 'property_invoices_tenant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('base')->table('property_invoices', function (Blueprint $table) {
            $table->dropIndex('property_invoices_tenant_created_idx');
            $table->dropIndex('property_invoices_tenant_due_idx');
            $table->dropIndex('property_invoices_tenant_issue_idx');
        });
    }
};
