<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->create('property_invoices', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workspace_property_id')->constrained('workspace_properties')->cascadeOnDelete();
            $table->foreignId('property_subscription_id')->nullable()->constrained('property_subscriptions')->nullOnDelete();
            $table->char('property_uuid', 36);
            $table->string('invoice_number', 80)->unique();
            $table->unsignedInteger('invoice_year');
            $table->unsignedInteger('sequence_number');
            $table->enum('status', ['paid', 'unpaid', 'overdue'])->default('unpaid')->index();
            $table->char('currency', 3)->default('TZS');
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedBigInteger('unit_price_cents')->default(0);
            $table->unsignedBigInteger('subtotal_amount_cents')->default(0);
            $table->unsignedBigInteger('total_amount_cents')->default(0);
            $table->unsignedBigInteger('balance_amount_cents')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['workspace_property_id', 'period_starts_on', 'period_ends_on'], 'property_invoices_property_period_unique');
            $table->index(['tenant_id', 'status', 'due_date'], 'property_invoices_tenant_status_due_idx');
            $table->index(['workspace_property_id', 'status', 'due_date'], 'property_invoices_property_status_due_idx');
            $table->index(['tenant_id', 'invoice_year', 'sequence_number'], 'property_invoices_tenant_year_sequence_idx');
            $table->index(['workspace_property_id', 'issue_date'], 'property_invoices_property_issue_idx');
            $table->index(['workspace_property_id', 'due_date'], 'property_invoices_property_due_idx');
            $table->index(['workspace_property_id', 'created_at'], 'property_invoices_property_created_idx');
        });

        Schema::connection('base')->create('property_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('property_invoice_id')->constrained('property_invoices')->cascadeOnDelete();
            $table->string('description', 200);
            $table->string('payment_period_label', 120);
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedBigInteger('unit_price_cents')->default(0);
            $table->unsignedBigInteger('amount_cents')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['property_invoice_id'], 'property_invoice_items_invoice_idx');
        });

        Schema::connection('base')->create('property_invoice_delivery_logs', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('property_invoice_id')->constrained('property_invoices')->cascadeOnDelete();
            $table->enum('channel', ['email', 'sms'])->index();
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending')->index();
            $table->string('recipient_name', 160)->nullable();
            $table->string('recipient_address', 190);
            $table->string('subject', 200)->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('attempts_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['property_invoice_id', 'channel', 'status'], 'property_invoice_delivery_invoice_channel_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('base')->dropIfExists('property_invoice_delivery_logs');
        Schema::connection('base')->dropIfExists('property_invoice_items');
        Schema::connection('base')->dropIfExists('property_invoices');
    }
};
