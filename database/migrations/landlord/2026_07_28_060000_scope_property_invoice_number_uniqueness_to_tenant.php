<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->table('property_invoices', function (Blueprint $table) {
            $table->dropUnique('property_invoices_invoice_number_unique');
            $table->unique(['tenant_id', 'invoice_number'], 'property_invoices_tenant_invoice_number_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('base')->table('property_invoices', function (Blueprint $table) {
            $table->dropUnique('property_invoices_tenant_invoice_number_unique');
            $table->unique('invoice_number', 'property_invoices_invoice_number_unique');
        });
    }
};
