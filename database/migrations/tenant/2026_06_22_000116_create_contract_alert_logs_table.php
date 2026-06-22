<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contract_alert_logs')) {
            return;
        }

        Schema::create('contract_alert_logs', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('contract_id')->constrained('customer_contracts')->cascadeOnDelete();
            $table->char('contract_uuid', 36);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->enum('event_type', ['expiring_soon', 'expired'])->index();
            $table->enum('channel', ['sms', 'email'])->index();
            $table->string('recipient_type', 30);
            $table->string('recipient_key', 120);
            $table->string('recipient_name')->nullable();
            $table->string('recipient_address', 190);
            $table->date('contract_end_date');
            $table->enum('status', ['success', 'failed'])->default('success')->index();
            $table->text('message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['contract_id', 'event_type', 'channel', 'recipient_key', 'contract_end_date'],
                'contract_alert_logs_unique_alert'
            );
            $table->index(['property_id', 'event_type', 'channel'], 'contract_alert_logs_property_event_channel_idx');
            $table->index(['contract_id', 'status'], 'contract_alert_logs_contract_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_alert_logs');
    }
};
