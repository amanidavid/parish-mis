<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->table('property_invoice_delivery_logs', function (Blueprint $table) {
            $table->index(
                ['status', 'last_attempt_at'],
                'property_invoice_delivery_status_attempted_idx'
            );
            $table->index(
                ['channel', 'last_attempt_at'],
                'property_invoice_delivery_channel_attempted_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::connection('base')->table('property_invoice_delivery_logs', function (Blueprint $table) {
            $table->dropIndex('property_invoice_delivery_channel_attempted_idx');
            $table->dropIndex('property_invoice_delivery_status_attempted_idx');
        });
    }
};
