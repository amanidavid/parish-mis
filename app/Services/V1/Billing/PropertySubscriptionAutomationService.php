<?php

namespace App\Services\V1\Billing;

use App\Models\Landlord\AutomationTaskRun;
use App\Models\Landlord\AutomationTaskSetting;
use App\Services\V1\Occupancy\ContractAlertService;
use App\Services\V1\Occupancy\CustomerContractAutomationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PropertySubscriptionAutomationService
{
    private ?array $defaultTaskDefinitions = null;

    /**
     * Create a new instance.
     */
    public function __construct(
        private PropertySubscriptionService $propertySubscriptionService,
        private CustomerContractAutomationService $customerContractAutomationService,
        private ContractAlertService $contractAlertService,
        private PropertySubAlertService $propertySubAlertService,
    ) {
    }

    /**
     * List task settings.
     */
    public function listTaskSettings()
    {
        $this->ensureDefaultTasks();

        return AutomationTaskSetting::query()
            ->orderBy('name')
            ->get();
    }

    /**
     * List task runs.
     */
    public function listTaskRuns(AutomationTaskSetting $taskSetting, int $perPage = 15): LengthAwarePaginator
    {
        return $taskSetting->runs()
            ->orderByDesc('started_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Update task setting.
     */
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

    /**
     * Handle the run now request.
     */
    public function runNow(AutomationTaskSetting $taskSetting): AutomationTaskRun
    {
        return $this->runTask($taskSetting, true, true)
            ?? throw new InvalidArgumentException('The requested automation task could not be started.');
    }

    /**
     * Handle run task by key.
     */
    public function runTaskByKey(string $taskKey, bool $force = false): ?AutomationTaskRun
    {
        $this->ensureDefaultTasks();

        $taskSetting = AutomationTaskSetting::query()
            ->where('task_key', $taskKey)
            ->first();

        if (!$taskSetting) {
            throw new InvalidArgumentException('The requested automation task could not be found.');
        }

        return $this->runTask($taskSetting, $force);
    }

    /**
     * Handle run due tasks.
     */
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
            ->pluck('id')
            ->each(function (int $taskId) use (&$executed) {
                if ($this->runTaskById($taskId, false, false) !== null) {
                    $executed++;
                }
            });

        return $executed;
    }

    /**
     * Run task.
     */
    private function runTask(AutomationTaskSetting $taskSetting, bool $force, bool $recordSkipped = true): ?AutomationTaskRun
    {
        $startedAt = now();
        [$lockedTask, $skipStatus, $skipMessage] = $this->claimTaskRun((int) $taskSetting->id, $force, $startedAt);

        if (!$lockedTask) {
            if (!$recordSkipped || !$skipStatus || !$skipMessage) {
                return null;
            }

            return $this->storeRunResult($taskSetting, $startedAt, 0, $skipStatus, $skipMessage);
        }

        $rowsAffected = 0;
        $status = AutomationTaskRun::STATUS_SUCCESS;
        $message = 'Automation task completed successfully.';

        try {
            $rowsAffected = match ($lockedTask->task_key) {
                    AutomationTaskSetting::TASK_PROPERTY_SUBSCRIPTION_EXPIRY_SYNC => $this->propertySubscriptionService->syncExpiredPropertySubscriptions(),
                    AutomationTaskSetting::TASK_CUSTOMER_CONTRACT_EXPIRY_SYNC => $this->customerContractAutomationService->syncReadyTenants(),
                    AutomationTaskSetting::TASK_CUSTOMER_CONTRACT_ALERTS => $this->contractAlertService->syncReadyTenants(),
                    AutomationTaskSetting::TASK_PROPERTY_SUBSCRIPTION_ALERTS => $this->propertySubAlertService->syncReadyTenants(),
                    default => throw new InvalidArgumentException('Unsupported automation task.'),
                };

            $message = $this->successMessageForTask($lockedTask->task_key, $rowsAffected);
        } catch (\Throwable $exception) {
            report($exception);
            $status = AutomationTaskRun::STATUS_FAILED;
            $message = $this->failureMessageForTask($lockedTask->task_key);
        }

        return $this->storeRunResult($lockedTask, $startedAt, $rowsAffected, $status, $message);
    }

    /**
     * Ensure default tasks.
     */
    private function ensureDefaultTasks(): void
    {
        $taskDefinitions = $this->defaultTasks();
        $existingTasks = AutomationTaskSetting::query()
            ->whereIn('task_key', array_keys($taskDefinitions))
            ->get()
            ->keyBy('task_key');

        $existingTaskKeys = $existingTasks->keys()->all();

        foreach (array_diff(array_keys($taskDefinitions), $existingTaskKeys) as $taskKey) {
            AutomationTaskSetting::query()->create([
                'task_key' => $taskKey,
                ...$taskDefinitions[$taskKey],
            ]);
        }

        foreach ($existingTasks as $taskKey => $taskSetting) {
            $definition = $taskDefinitions[$taskKey] ?? null;

            if ($definition === null) {
                continue;
            }

            $updates = [];

            if ($taskSetting->name !== $definition['name']) {
                $updates['name'] = $definition['name'];
            }

            if ($taskSetting->description !== $definition['description']) {
                $updates['description'] = $definition['description'];
            }

            if (($taskSetting->meta ?? []) !== ($definition['meta'] ?? [])) {
                $updates['meta'] = $definition['meta'] ?? [];
            }

            if ($updates !== []) {
                $taskSetting->fill($updates)->save();
            }
        }
    }

    /**
     * Ensure supported task.
     */
    private function ensureSupportedTask(AutomationTaskSetting $taskSetting): void
    {
        if (!array_key_exists($taskSetting->task_key, $this->defaultTasks())) {
            throw new InvalidArgumentException('The requested automation task is not supported.');
        }
    }

    /**
     * Default tasks.
     */
    private function defaultTasks(): array
    {
        if ($this->defaultTaskDefinitions !== null) {
            return $this->defaultTaskDefinitions;
        }

        $defaultNextRunAt = now()->addMinutes(15);

        return $this->defaultTaskDefinitions = [
            AutomationTaskSetting::TASK_CUSTOMER_CONTRACT_EXPIRY_SYNC => [
                'name' => 'Customer Contract Lifecycle Sync',
                'description' => 'Automatically activates due draft contracts, expires ended contracts, and refreshes occupancy across ready workspaces.',
                'enabled' => true,
                'schedule_mode' => AutomationTaskSetting::MODE_INTERVAL,
                'interval_minutes' => 15,
                'timezone' => 'Africa/Nairobi',
                'next_run_at' => $defaultNextRunAt->copy(),
                'meta' => ['supports_run_now' => true],
            ],
            AutomationTaskSetting::TASK_PROPERTY_SUBSCRIPTION_EXPIRY_SYNC => [
                'name' => 'Property Subscription Expiry Sync',
                'description' => 'Automatically marks property subscriptions as expired after their paid coverage ends.',
                'enabled' => true,
                'schedule_mode' => AutomationTaskSetting::MODE_INTERVAL,
                'interval_minutes' => 15,
                'timezone' => 'Africa/Nairobi',
                'next_run_at' => $defaultNextRunAt->copy(),
                'meta' => ['supports_run_now' => true],
            ],
            AutomationTaskSetting::TASK_CUSTOMER_CONTRACT_ALERTS => [
                'name' => 'Contract Alerts',
                'description' => 'Sends expiring soon and expired contract alerts to occupants and contract supervisors.',
                'enabled' => true,
                'schedule_mode' => AutomationTaskSetting::MODE_INTERVAL,
                'interval_minutes' => 15,
                'timezone' => 'Africa/Nairobi',
                'next_run_at' => $defaultNextRunAt->copy(),
                'meta' => ['supports_run_now' => true],
            ],
            AutomationTaskSetting::TASK_PROPERTY_SUBSCRIPTION_ALERTS => [
                'name' => 'Property Alerts',
                'description' => 'Sends expiring soon and expiry day property subscription alerts to assigned property staff.',
                'enabled' => true,
                'schedule_mode' => AutomationTaskSetting::MODE_INTERVAL,
                'interval_minutes' => 15,
                'timezone' => 'Africa/Nairobi',
                'next_run_at' => $defaultNextRunAt->copy(),
                'meta' => ['supports_run_now' => true],
            ],
        ];
    }

    /**
     * Success message for task.
     */
    private function successMessageForTask(string $taskKey, int $rowsAffected): string
    {
        return match ($taskKey) {
            AutomationTaskSetting::TASK_CUSTOMER_CONTRACT_EXPIRY_SYNC => $rowsAffected > 0
                ? sprintf('Customer contract lifecycle sync completed successfully. %d contract, unit, and customer status rows were updated.', $rowsAffected)
                : 'Customer contract lifecycle sync completed successfully. No draft activations, expiries, or occupancy updates were required.',
            AutomationTaskSetting::TASK_PROPERTY_SUBSCRIPTION_EXPIRY_SYNC => $rowsAffected > 0
                ? sprintf('Automation task completed successfully. %d property subscription rows were updated.', $rowsAffected)
                : 'Automation task completed successfully. No property subscription rows required updating.',
            AutomationTaskSetting::TASK_CUSTOMER_CONTRACT_ALERTS => $rowsAffected > 0
                ? sprintf('Automation task completed successfully. %d contract alerts were sent.', $rowsAffected)
                : 'Automation task completed successfully. No contract alerts were due.',
            AutomationTaskSetting::TASK_PROPERTY_SUBSCRIPTION_ALERTS => $rowsAffected > 0
                ? sprintf('Automation task completed successfully. %d property subscription alerts were sent.', $rowsAffected)
                : 'Automation task completed successfully. No property subscription alerts were due.',
            default => 'Automation task completed successfully.',
        };
    }

    /**
     * Failure message for task.
     */
    private function failureMessageForTask(string $taskKey): string
    {
        return match ($taskKey) {
            AutomationTaskSetting::TASK_CUSTOMER_CONTRACT_ALERTS => 'Contract alerts could not be processed at the moment. Please check the notification configuration and try again.',
            AutomationTaskSetting::TASK_PROPERTY_SUBSCRIPTION_ALERTS => 'Property subscription alerts could not be processed at the moment. Please check the notification configuration and try again.',
            AutomationTaskSetting::TASK_CUSTOMER_CONTRACT_EXPIRY_SYNC => 'Customer contract lifecycle sync could not be completed at the moment. Please try again shortly.',
            AutomationTaskSetting::TASK_PROPERTY_SUBSCRIPTION_EXPIRY_SYNC => 'Property subscription expiry sync could not be completed at the moment. Please try again shortly.',
            default => 'Automation task could not be completed at the moment. Please try again shortly.',
        };
    }

    /**
     * Determine whether due.
     */
    private function isDue(AutomationTaskSetting $taskSetting, Carbon $now): bool
    {
        if (!$taskSetting->enabled) {
            return false;
        }

        return $taskSetting->next_run_at === null || Carbon::parse($taskSetting->next_run_at)->lte($now);
    }

    /**
     * Run task by id.
     */
    private function runTaskById(int $taskId, bool $force, bool $recordSkipped): ?AutomationTaskRun
    {
        $taskSetting = AutomationTaskSetting::query()->find($taskId);

        if (!$taskSetting) {
            return null;
        }

        return $this->runTask($taskSetting, $force, $recordSkipped);
    }

    /**
     * Claim task run.
     *
     * @return array{0: ?AutomationTaskSetting, 1: ?string, 2: ?string}
     */
    private function claimTaskRun(int $taskId, bool $force, Carbon $startedAt): array
    {
        return DB::connection('base')->transaction(function () use ($taskId, $force, $startedAt) {
            $lockedTask = AutomationTaskSetting::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first();

            if (!$lockedTask) {
                return [null, null, null];
            }

            $this->ensureSupportedTask($lockedTask);

            if (!$force && !$lockedTask->enabled) {
                return [null, AutomationTaskRun::STATUS_SKIPPED, 'Automation task is disabled.'];
            }

            if (!$force && !$this->isDue($lockedTask, $startedAt)) {
                return [null, AutomationTaskRun::STATUS_SKIPPED, 'Automation task is not due yet.'];
            }

            $lockedTask->forceFill([
                'next_run_at' => $lockedTask->enabled
                    ? $this->computeNextRunAt(
                        $lockedTask->schedule_mode,
                        $lockedTask->interval_minutes,
                        $lockedTask->run_at_time,
                        $lockedTask->timezone,
                        $startedAt
                    )
                    : null,
            ])->save();

            return [$lockedTask->fresh(), null, null];
        });
    }

    /**
     * Store run result.
     */
    private function storeRunResult(
        AutomationTaskSetting $taskSetting,
        Carbon $startedAt,
        int $rowsAffected,
        string $status,
        string $message
    ): AutomationTaskRun {
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
                'next_run_at' => $lockedTask->enabled ? $lockedTask->next_run_at : null,
            ])->save();

            return $run;
        });
    }

    /**
     * Compute next run at.
     */
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
