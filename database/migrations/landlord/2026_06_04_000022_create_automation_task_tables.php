<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('base')->create('automation_task_settings', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('task_key', 120)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->enum('schedule_mode', ['interval', 'daily'])->default('interval');
            $table->unsignedSmallInteger('interval_minutes')->nullable();
            $table->time('run_at_time')->nullable();
            $table->string('timezone', 80)->default('Africa/Nairobi');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->text('last_message')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'next_run_at'], 'automation_task_settings_enabled_next_run_idx');
        });

        Schema::connection('base')->create('automation_task_runs', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('automation_task_setting_id')->constrained('automation_task_settings')->cascadeOnDelete();
            $table->enum('status', ['success', 'failed', 'skipped'])->default('success');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('rows_affected')->default(0);
            $table->text('message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['automation_task_setting_id', 'started_at'], 'automation_task_runs_task_started_idx');
            $table->index(['status', 'started_at'], 'automation_task_runs_status_started_idx');
        });

        DB::connection('base')->table('automation_task_settings')->insert([
            'uuid' => (string) str()->uuid(),
            'task_key' => 'property_subscription_expiry_sync',
            'name' => 'Property Subscription Expiry Sync',
            'description' => 'Automatically marks property subscriptions as expired after their paid coverage ends.',
            'enabled' => true,
            'schedule_mode' => 'interval',
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
        ]);
    }

    public function down(): void
    {
        Schema::connection('base')->dropIfExists('automation_task_runs');
        Schema::connection('base')->dropIfExists('automation_task_settings');
    }
};
