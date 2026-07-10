<?php

use App\Models\Landlord\AutomationTaskSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->create('subscription_trial_extensions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('extended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('days');
            $table->timestamp('old_trial_ends_at');
            $table->timestamp('new_trial_ends_at');
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(
                ['subscription_id', 'created_at'],
                'subscription_trial_extensions_subscription_created_idx'
            );
            $table->index(
                ['tenant_id', 'created_at'],
                'subscription_trial_extensions_tenant_created_idx'
            );
        });

        Schema::connection('base')->create('workspace_trial_alert_logs', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->char('tenant_uuid', 36);
            $table->char('subscription_uuid', 36);
            $table->enum('event_type', ['expiring_soon', 'expires_today'])->index();
            $table->enum('channel', ['sms', 'email'])->index();
            $table->string('recipient_type', 40);
            $table->string('recipient_key', 160);
            $table->string('recipient_name')->nullable();
            $table->string('recipient_address');
            $table->string('subject')->nullable();
            $table->enum('status', ['success', 'failed'])->default('success')->index();
            $table->unsignedSmallInteger('attempts_count')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('trial_ends_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['subscription_id', 'event_type', 'channel', 'recipient_key', 'trial_ends_at'],
                'workspace_trial_alert_logs_unique'
            );
            $table->index(
                ['tenant_id', 'event_type', 'status'],
                'workspace_trial_alert_logs_tenant_event_status_idx'
            );
        });

        Schema::connection('base')->table('user_tenants', function (Blueprint $table) {
            $table->index(
                ['tenant_id', 'is_owner', 'user_id'],
                'user_tenants_tenant_owner_user_idx'
            );
        });

        DB::connection('base')->table('automation_task_settings')->updateOrInsert(
            ['task_key' => AutomationTaskSetting::TASK_WORKSPACE_TRIAL_ALERTS],
            [
                'uuid' => (string) str()->uuid(),
                'name' => 'Workspace Trial Alerts',
                'description' => 'Sends expiring soon and expiry day workspace free trial alerts to workspace owners.',
                'enabled' => true,
                'schedule_mode' => AutomationTaskSetting::MODE_INTERVAL,
                'interval_minutes' => 15,
                'run_at_time' => null,
                'timezone' => 'Africa/Nairobi',
                'last_run_at' => null,
                'next_run_at' => now()->addMinutes(15),
                'last_status' => null,
                'last_message' => null,
                'updated_by_user_id' => null,
                'meta' => json_encode(['supports_run_now' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::connection('base')->table('automation_task_settings')
            ->where('task_key', AutomationTaskSetting::TASK_WORKSPACE_TRIAL_ALERTS)
            ->delete();

        Schema::connection('base')->table('user_tenants', function (Blueprint $table) {
            $table->dropIndex('user_tenants_tenant_owner_user_idx');
        });

        Schema::connection('base')->dropIfExists('workspace_trial_alert_logs');
        Schema::connection('base')->dropIfExists('subscription_trial_extensions');
    }
};
