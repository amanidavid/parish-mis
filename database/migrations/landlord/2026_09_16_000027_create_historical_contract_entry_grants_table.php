<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->create('historical_contract_entry_grants', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workspace_property_id')->nullable()->constrained('workspace_properties')->cascadeOnDelete();
            $table->enum('status', ['active', 'revoked'])->default('active');
            $table->date('historical_start_date_from');
            $table->date('historical_start_date_to');
            $table->timestamp('usable_from');
            $table->timestamp('expires_at');
            $table->text('reason');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at');
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'expires_at'], 'historical_contract_grants_tenant_status_expiry_idx');
            $table->index(['tenant_id', 'workspace_property_id', 'status', 'expires_at'], 'historical_contract_grants_scope_status_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('base')->dropIfExists('historical_contract_entry_grants');
    }
};
