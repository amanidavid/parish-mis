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
        Schema::connection('base')->create('property_subscription_alert_logs', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('property_subscription_id')->constrained('property_subscriptions')->cascadeOnDelete();
            $table->foreignId('workspace_property_id')->constrained('workspace_properties')->cascadeOnDelete();
            $table->char('property_subscription_uuid', 36);
            $table->char('property_uuid', 36);
            $table->enum('event_type', ['expiring_soon', 'expires_today'])->index();
            $table->enum('channel', ['sms', 'email'])->index();
            $table->string('recipient_type', 40);
            $table->string('recipient_key', 160);
            $table->string('recipient_name')->nullable();
            $table->string('recipient_address');
            $table->enum('status', ['success', 'failed'])->default('success')->index();
            $table->text('message')->nullable();
            $table->date('period_ends_on');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['property_subscription_id', 'event_type', 'channel', 'recipient_key', 'period_ends_on'],
                'property_sub_alert_logs_unique'
            );
            $table->index(['tenant_id', 'event_type', 'status'], 'property_sub_alert_logs_tenant_event_status_idx');
        });

        DB::connection('base')->table('automation_task_settings')->updateOrInsert(
            ['task_key' => AutomationTaskSetting::TASK_PROPERTY_SUBSCRIPTION_ALERTS],
            [
                'uuid' => (string) str()->uuid(),
                'name' => 'Property Alerts',
                'description' => 'Sends expiring soon and expiry day property subscription alerts to assigned property staff.',
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
            ->where('task_key', AutomationTaskSetting::TASK_PROPERTY_SUBSCRIPTION_ALERTS)
            ->delete();

        Schema::connection('base')->dropIfExists('property_subscription_alert_logs');
    }
};
