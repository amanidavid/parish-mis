<?php

use App\Models\Landlord\AutomationTaskSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('base')->table('automation_task_settings')->updateOrInsert(
            ['task_key' => AutomationTaskSetting::TASK_CUSTOMER_CONTRACT_ALERTS],
            [
                'uuid' => (string) str()->uuid(),
                'name' => 'Contract Alerts',
                'description' => 'Sends expiring soon and expired contract alerts to occupants and contract supervisors.',
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
            ->where('task_key', AutomationTaskSetting::TASK_CUSTOMER_CONTRACT_ALERTS)
            ->delete();
    }
};
