<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('base')->table('tenants', function (Blueprint $table) {
            if (!Schema::connection('base')->hasColumn('tenants', 'provisioning_status')) {
                $table->string('provisioning_status', 32)->default('pending')->after('status')->index();
            }

            if (!Schema::connection('base')->hasColumn('tenants', 'provision_attempts')) {
                $table->unsignedInteger('provision_attempts')->default(0)->after('provisioning_status');
            }

            if (!Schema::connection('base')->hasColumn('tenants', 'provision_error')) {
                $table->text('provision_error')->nullable()->after('provision_attempts');
            }

            if (!Schema::connection('base')->hasColumn('tenants', 'provision_started_at')) {
                $table->timestamp('provision_started_at')->nullable()->after('provision_error');
            }

            if (!Schema::connection('base')->hasColumn('tenants', 'provisioned_at')) {
                $table->timestamp('provisioned_at')->nullable()->after('provision_started_at');
            }
        });

        if (!Schema::connection('base')->hasTable('tenant_database_provision_logs')) {
            Schema::connection('base')->create('tenant_database_provision_logs', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('status', 32)->index();
                $table->string('step', 100)->index();
                $table->text('message')->nullable();
                $table->json('context')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('base')->dropIfExists('tenant_database_provision_logs');

        Schema::connection('base')->table('tenants', function (Blueprint $table) {
            $columns = [
                'provisioning_status',
                'provision_attempts',
                'provision_error',
                'provision_started_at',
                'provisioned_at',
            ];

            foreach ($columns as $column) {
                if (Schema::connection('base')->hasColumn('tenants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
