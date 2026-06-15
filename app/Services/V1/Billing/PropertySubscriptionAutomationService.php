<?php

namespace App\Services\V1\Billing;

use App\Models\Landlord\AutomationTaskRun;
use App\Models\Landlord\AutomationTaskSetting;
use App\Services\V1\Occupancy\CustomerContractAutomationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PropertySubscriptionAutomationService
{
    public function __construct(
        private PropertySubscriptionService $propertySubscriptionService,
        private CustomerContractAutomationService $customerContractAutomationService,
    ) {
    }

    public function listTaskSettings()
    {
        $this->ensureDefaultTasks();

        return AutomationTaskSetting::query()
            ->orderBy('name')
            ->get();
    }

    public function listTaskRuns(AutomationTaskSetting $taskSetting, int $perPage = 15): LengthAwarePaginator
    {
        return $taskSetting->runs()
            ->orderByDesc('started_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function updateTaskSetting(AutomationTaskSetting $taskSetting, array $payload, ?object $adminUser = null): AutomationTaskSetting
    {
        $this->ensureSupportedTask($taskSetting);

        $enabled = array_key_exists('enabled', $payload) ? (bool) $payload['enabled'] : (bool) $taskSetting->enabled;
        $scheduleMode = $payload['schedule_mode'] ?? $taskSetting->schedule_mode;
        $intervalMinutes = $scheduleMode === AutomationTaskSetting::MODE_INTERVAL
            ? (int) ($payload['interval_minutes'] ?? $taskSetting->interval_minutes ?? 15)
            : null;
        $runAtTime = $scheduleMode === AutomationTaskSetting::MODE_DAILY
            ? ($payload['run_at_time'] ?? $taskSetting->run_at_time)
            : null;
        $timezone = $payload['timezone'] ?? $taskSetting->timezone ?? 'Africa/Nairobi';
        $nextRunAt = $enabled ? $this->computeNextRunAt($scheduleMode, $intervalMinutes, $runAtTime, $timezone, now()) : null;

        $taskSetting->fill([
            'enabled' => $enabled,
            'schedule_mode' => $scheduleMode,
            'interval_minutes' => $intervalMinutes,
            'run_at_time' => $runAtTime,
            'timezone' => $timezone,
            'next_run_at' => $nextRunAt,
            'updated_by_user_id' => $adminUser?->id,
        ])->save();

        return $taskSetting->fresh();
    }

    public function runNow(AutomationTaskSetting $taskSetting): AutomationTaskRun
    {
        return $this->runTask($taskSetting, true);
    }

    public function runTaskByKey(string $taskKey, bool $force = false): ?AutomationTaskRun
    {
        $taskSetting = AutomationTaskSetting::query()
            ->where('task_key', $taskKey)
            ->first();

        if (!$taskSetting) {
            throw new InvalidArgumentException('The requested automation task could not be found.');
        }

        return $this->runTask($taskSetting, $force);
    }

    public function runDueTasks(): int
    {
        $this->ensureDefaultTasks();
        $executed = 0;

        AutomationTaskSetting::query()
            ->where('enabled', true)
            ->where(function ($query) {
                $query
                    ->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            })
            ->orderBy('next_run_at')
            ->get()
            ->each(function (AutomationTaskSetting $taskSetting) use (&$executed) {
                $this->runTask($taskSetting, false);
                $executed++;
            });

        return $executed;
    }

    private function runTask(AutomationTaskSetting $taskSetting, bool $force): AutomationTaskRun
    {
        $this->ensureSupportedTask($taskSetting);
        $startedAt = now();
        $rowsAffected = 0;
        $status = AutomationTaskRun::STATUS_SUCCESS;
        $message = 'Automation task completed successfully.';

        try {
            if (!$force && !$taskSetting->enabled) {
                $status = AutomationTaskRun::STATUS_SKIPPED;
                $message = 'Automation task is disabled.';
            } elseif (!$force && !$this->isDue($taskSetting, $startedAt)) {
                $status = AutomationTaskRun::STATUS_SKIPPED;
                $message = 'Automation task is not due yet.';
            } else {
                $rowsAffected = match ($taskSetting->task_key) {
                    AutomationTaskSetting::TASK_PROPERTY_SUBSCRIPTION_EXPIRY_SYNC => $this->propertySubscriptionService->syncExpiredPropertySubscriptions(),
                    AutomationTaskSetting::TASK_CUSTOMER_CONTRACT_EXPIRY_SYNC => $this->customerContractAutomationService->syncReadyTenants(),
                    default => throw new InvalidArgumentException('Unsupported automation task.'),
                };

                $message = $this->successMessageForTask($taskSetting->task_key, $rowsAffected);
            }
        } catch (\Throwable $exception) {
            $status = AutomationTaskRun::STATUS_FAILED;
            $message = $exception->getMessage();
        }

        return DB::connection('base')->transaction(function () use ($taskSetting, $startedAt, $rowsAffected, $status, $message) {
            $lockedTask = AutomationTaskSetting::query()
                ->whereKey($taskSetting->id)
                ->lockForUpdate()
                ->firstOrFail();

            $finishedAt = now();
            $run = AutomationTaskRun::query()->create([
                'automation_task_setting_id' => $lockedTask->id,
                'status' => $status,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'rows_affected' => $rowsAffected,
                'message' => $message,
            ]);

            $lockedTask->fill([
                'last_run_at' => $finishedAt,
                'last_status' => $status,
                'last_message' => $message,
                'next_run_at' => $lockedTask->enabled
                    ? $this->computeNextRunAt(
                        $lockedTask->schedule_mode,
                        $lockedTask->interval_minutes,
                        $lockedTask->run_at_time,
                        $lockedTask->timezone,
                        $finishedAt
                    )
                    : null,
            ])->save();

            return $run;
        });
    }

    private function ensureDefaultTasks(): void
    {
        foreach ($this->defaultTasks() as $taskKey => $taskDefinition) {
            AutomationTaskSetting::query()->firstOrCreate(
                ['task_key' => $taskKey],
                $taskDefinition
            );
        }
    }

    private function ensureSupportedTask(AutomationTaskSetting $taskSetting): void
    {
        if (!array_key_exists($taskSetting->task_key, $this->defaultTasks())) {
            throw new InvalidArgumentException('The requested automation task is not supported.');
        }
    }

    private function defaultTasks(): array
    {
        return [
            AutomationTaskSetting::TASK_CUSTOMER_CONTRACT_EXPIRY_SYNC => [
                'name' => 'Customer Contract Expiry Sync',
                'description' => 'Automatically expires ended customer contracts and refreshes unit occupancy across ready workspaces.',
                'enabled' => true,
                'schedule_mode' => AutomationTaskSetting::MODE_INTERVAL,
                'interval_minutes' => 15,
                'timezone' => 'Africa/Nairobi',
                'next_run_at' => now()->addMinutes(15),
                'meta' => ['supports_run_now' => true],
            ],
            AutomationTaskSetting::TASK_PROPERTY_SUBSCRIPTION_EXPIRY_SYNC => [
                'name' => 'Property Subscription Expiry Sync',
                'description' => 'Automatically marks property subscriptions as expired after their paid coverage ends.',
                'enabled' => true,
                'schedule_mode' => AutomationTaskSetting::MODE_INTERVAL,
                'interval_minutes' => 15,
                'timezone' => 'Africa/Nairobi',
                'next_run_at' => now()->addMinutes(15),
                'meta' => ['supports_run_now' => true],
            ],
        ];
    }

    private function successMessageForTask(string $taskKey, int $rowsAffected): string
    {
        return match ($taskKey) {
            AutomationTaskSetting::TASK_CUSTOMER_CONTRACT_EXPIRY_SYNC => $rowsAffected > 0
                ? sprintf('Automation task completed successfully. %d customer contract and unit rows were updated.', $rowsAffected)
                : 'Automation task completed successfully. No customer contract or unit rows required updating.',
            AutomationTaskSetting::TASK_PROPERTY_SUBSCRIPTION_EXPIRY_SYNC => $rowsAffected > 0
                ? sprintf('Automation task completed successfully. %d property subscription rows were updated.', $rowsAffected)
                : 'Automation task completed successfully. No property subscription rows required updating.',
            default => 'Automation task completed successfully.',
        };
    }

    private function isDue(AutomationTaskSetting $taskSetting, Carbon $now): bool
    {
        if (!$taskSetting->enabled) {
            return false;
        }

        return $taskSetting->next_run_at === null || Carbon::parse($taskSetting->next_run_at)->lte($now);
    }

    private function computeNextRunAt(
        string $scheduleMode,
        ?int $intervalMinutes,
        ?string $runAtTime,
        ?string $timezone,
        Carbon $from
    ): ?Carbon {
        $timezone = $timezone ?: 'Africa/Nairobi';
        $localizedFrom = $from->copy()->timezone($timezone);

        if ($scheduleMode === AutomationTaskSetting::MODE_DAILY) {
            $runAtTime = $runAtTime ?: '00:00:00';
            [$hour, $minute, $second] = array_pad(explode(':', $runAtTime), 3, 0);
            $candidate = $localizedFrom->copy()->setTime((int) $hour, (int) $minute, (int) $second);

            if ($candidate->lte($localizedFrom)) {
                $candidate->addDay();
            }

            return $candidate->utc();
        }

        return $from->copy()->addMinutes(max((int) ($intervalMinutes ?? 15), 1));
    }
}
