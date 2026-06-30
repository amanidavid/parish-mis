<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->decimal('monthly_rent_amount', 15, 2)->default(0)->after('unit_number');
            $table->char('rent_currency', 3)->default('TZS')->after('monthly_rent_amount');
            $table->index(['status', 'monthly_rent_amount'], 'units_status_rent_amount_index');
        });

        Schema::table('customer_contracts', function (Blueprint $table) {
            $table->unsignedInteger('contract_months')->default(1)->after('end_date');
            $table->decimal('unit_price_at_contract', 15, 2)->default(0)->after('contract_months');
            $table->decimal('expected_total_amount', 15, 2)->default(0)->after('unit_price_at_contract');
            $table->decimal('final_payable_amount', 15, 2)->default(0)->after('expected_total_amount');
            $table->decimal('paid_amount_total', 15, 2)->default(0)->after('final_payable_amount');
            $table->decimal('refund_amount_total', 15, 2)->default(0)->after('paid_amount_total');
            $table->decimal('net_collected_amount', 15, 2)->default(0)->after('refund_amount_total');
            $table->decimal('outstanding_balance', 15, 2)->default(0)->after('net_collected_amount');
            $table->string('payment_status', 20)->default('unpaid')->after('status');
            $table->date('termination_date')->nullable()->after('payment_status');
            $table->text('termination_reason')->nullable()->after('termination_date');
            $table->unsignedInteger('terminated_used_months')->nullable()->after('termination_reason');
            $table->unsignedInteger('terminated_unused_months')->nullable()->after('terminated_used_months');

            $table->index(['payment_status', 'status'], 'customer_contracts_payment_status_status_index');
            $table->index(['termination_date', 'status'], 'customer_contracts_termination_date_status_index');
        });

        Schema::create('customer_contract_transactions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('customer_contract_id')->constrained('customer_contracts')->cascadeOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3)->default('TZS');
            $table->date('transaction_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['customer_contract_id', 'type', 'transaction_date'], 'contract_transactions_contract_type_date_index');
            $table->index(['transaction_date', 'type'], 'contract_transactions_date_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contract_transactions');

        Schema::table('customer_contracts', function (Blueprint $table) {
            $table->dropIndex('customer_contracts_payment_status_status_index');
            $table->dropIndex('customer_contracts_termination_date_status_index');
            $table->dropColumn([
                'contract_months',
                'unit_price_at_contract',
                'expected_total_amount',
                'final_payable_amount',
                'paid_amount_total',
                'refund_amount_total',
                'net_collected_amount',
                'outstanding_balance',
                'payment_status',
                'termination_date',
                'termination_reason',
                'terminated_used_months',
                'terminated_unused_months',
            ]);
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex('units_status_rent_amount_index');
            $table->dropColumn(['monthly_rent_amount', 'rent_currency']);
        });
    }
};
