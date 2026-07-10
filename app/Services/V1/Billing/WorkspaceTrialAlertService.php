<?php

namespace App\Services\V1\Billing;

use App\Services\V1\Concerns\DispatchesAlertsWithRetry;
use App\Services\V1\Messaging\SmsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class WorkspaceTrialAlertService
{
    use DispatchesAlertsWithRetry;

    private const EVENT_EXPIRING_SOON = 'expiring_soon';
    private const EVENT_EXPIRES_TODAY = 'expires_today';

    public function __construct(
        private SmsService $smsService,
    ) {
    }

    public function syncReadyTenants(?string $tenantUuid = null, int $chunk = 100): int
    {
        if (!$this->hasEnabledChannels()) {
            return 0;
        }

        $sentAlerts = 0;

        $targets = [
            self::EVENT_EXPIRING_SOON => $this->targetWindow((int) config('workspace_trial_alerts.warning_days', 7)),
            self::EVENT_EXPIRES_TODAY => $this->targetWindow(0),
        ];

        foreach ($targets as $eventType => [$windowStart, $windowEnd]) {
            $query = $this->baseAlertQuery($tenantUuid)
                ->whereBetween('subscriptions.trial_ends_at', [$windowStart, $windowEnd]);

            $query->orderBy('subscriptions.id')
                ->chunkById(max($chunk, 1), function ($rows) use (&$sentAlerts, $eventType) {
                    $sentAlerts += $this->processRows($rows, $eventType);
                }, 'subscriptions.id', 'subscription_id');
        }

        return $sentAlerts;
    }

    private function processRows($rows, string $eventType): int
    {
        $sentAlerts = 0;
        $timestamp = now();
        $subscriptionIds = $rows->pluck('subscription_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $existingLogs = $this->existingLogs($subscriptionIds, $eventType);

        foreach ($rows as $row) {
            [$subject, $message] = $this->buildMessage($row, $eventType);

            foreach ($this->enabledChannels() as $channel) {
                $address = $channel === 'sms'
                    ? trim((string) ($row->recipient_phone ?? ''))
                    : trim((string) ($row->recipient_email ?? ''));

                if ($address === '') {
                    continue;
                }

                $logKey = $this->logKey(
                    (int) $row->subscription_id,
                    (string) $row->trial_ends_at,
                    $eventType,
                    $channel,
                    (string) $row->recipient_key
                );

                if (($existingLogs[$logKey]['status'] ?? null) === 'success') {
                    continue;
                }

                $result = $this->dispatchAlertWithRetry(function () use (
                    $channel,
                    $address,
                    $subject,
                    $message,
                    $row,
                    $eventType
                ): void {
                    $this->dispatchChannel($channel, $address, $subject, $message, $row, $eventType);
                }, 'workspace_trial_alerts');

                if ($result['status'] === 'success') {
                    $sentAlerts++;
                }

                $existingUuid = $existingLogs[$logKey]['uuid'] ?? null;
                $existingAttempts = (int) ($existingLogs[$logKey]['attempts_count'] ?? 0);
                $this->upsertLog(
                    $row,
                    $channel,
                    $address,
                    $subject,
                    $eventType,
                    $result['status'],
                    $result['error'],
                    $existingAttempts + $result['attempts'],
                    $timestamp,
                    $existingUuid
                );
                $existingLogs[$logKey] = [
                    'uuid' => $existingUuid,
                    'status' => $result['status'],
                    'attempts_count' => $existingAttempts + $result['attempts'],
                ];
            }
        }

        return $sentAlerts;
    }

    private function baseAlertQuery(?string $tenantUuid = null)
    {
        $query = DB::connection('base')->table('subscriptions')
            ->join('tenants', 'tenants.id', '=', 'subscriptions.tenant_id')
            ->join('user_tenants', function ($join) {
                $join->on('user_tenants.tenant_id', '=', 'tenants.id')
                    ->where('user_tenants.is_owner', true);
            })
            ->join('users', 'users.id', '=', 'user_tenants.user_id')
            ->select([
                'subscriptions.id as subscription_id',
                'subscriptions.uuid as subscription_uuid',
                'subscriptions.tenant_id',
                'subscriptions.trial_ends_at',
                'tenants.uuid as tenant_uuid',
                'tenants.name as tenant_name',
                'tenants.display_name as tenant_display_name',
                'users.id as recipient_user_id',
                'users.name as recipient_name',
                'users.email as recipient_email',
                'users.phone as recipient_phone',
            ])
            ->selectRaw("CONCAT('owner:', users.id) as recipient_key")
            ->where('subscriptions.status', 'trialing')
            ->where('tenants.provisioning_status', 'ready')
            ->whereNotNull('subscriptions.trial_ends_at');

        if (!empty($tenantUuid)) {
            $query->where('tenants.uuid', $tenantUuid);
        }

        return $query;
    }

    private function targetWindow(int $daysFromToday): array
    {
        $target = Carbon::today($this->alertTimezone())->addDays($daysFromToday);

        return [
            $target->copy()->startOfDay()->format('Y-m-d H:i:s'),
            $target->copy()->endOfDay()->format('Y-m-d H:i:s'),
        ];
    }

    private function buildMessage(object $row, string $eventType): array
    {
        $trialEndsAt = Carbon::parse((string) $row->trial_ends_at)->format('Y-m-d');
        $workspaceName = $row->tenant_display_name ?: $row->tenant_name;
        $subject = $eventType === self::EVENT_EXPIRING_SOON
            ? 'Trial Expiring Soon'
            : 'Trial Ends Today';

        $message = $eventType === self::EVENT_EXPIRING_SOON
            ? sprintf(
                'Your workspace free trial for %s ends on %s. Renew early to keep access active.',
                $workspaceName,
                $trialEndsAt
            )
            : sprintf(
                'Your workspace free trial for %s ends today on %s. Renew now to avoid interruption.',
                $workspaceName,
                $trialEndsAt
            );

        return [$subject, $message];
    }

    private function dispatchChannel(
        string $channel,
        string $address,
        string $subject,
        string $message,
        object $row,
        string $eventType
    ): void {
        if ($channel === 'sms') {
            $this->smsService->sendText($address, $message, null, [
                'type' => 'workspace_trial_alert',
                'event' => $eventType,
                'subscription_uuid' => $row->subscription_uuid,
                'workspace_uuid' => $row->tenant_uuid,
                'recipient_type' => 'owner',
            ]);

            return;
        }

        Mail::raw($this->formatEmailMessage($message), function ($mail) use ($address, $row, $subject) {
            $mail->to($address, (string) ($row->recipient_name ?? 'Recipient'))
                ->subject($subject);
        });
    }

    private function formatEmailMessage(string $message): string
    {
        return "Hello,\n\n"
            .$message
            ."\n\nPlease do not reply to this email. This mailbox is not monitored."
            ."\n\nRegards,\nZABA Team";
    }

    private function existingLogs(array $subscriptionIds, string $eventType): array
    {
        if ($subscriptionIds === []) {
            return [];
        }

        $logs = DB::connection('base')->table('workspace_trial_alert_logs')
            ->whereIn('subscription_id', $subscriptionIds)
            ->where('event_type', $eventType)
            ->get();

        $mapped = [];
        foreach ($logs as $log) {
            $mapped[$this->logKey(
                (int) $log->subscription_id,
                (string) $log->trial_ends_at,
                $log->event_type,
                $log->channel,
                $log->recipient_key
            )] = [
                'uuid' => $log->uuid,
                'status' => $log->status,
                'attempts_count' => (int) ($log->attempts_count ?? 0),
            ];
        }

        return $mapped;
    }

    private function upsertLog(
        object $row,
        string $channel,
        string $address,
        string $subject,
        string $eventType,
        string $status,
        ?string $error,
        int $attemptsCount,
        Carbon $timestamp,
        ?string $existingUuid = null
    ): void {
        DB::connection('base')->table('workspace_trial_alert_logs')->updateOrInsert(
            [
                'subscription_id' => $row->subscription_id,
                'event_type' => $eventType,
                'channel' => $channel,
                'recipient_key' => $row->recipient_key,
                'trial_ends_at' => $row->trial_ends_at,
            ],
            [
                'uuid' => $existingUuid ?: (string) str()->uuid(),
                'tenant_id' => $row->tenant_id,
                'tenant_uuid' => $row->tenant_uuid,
                'subscription_uuid' => $row->subscription_uuid,
                'recipient_type' => 'owner',
                'recipient_name' => $row->recipient_name,
                'recipient_address' => $address,
                'subject' => $subject,
                'status' => $status,
                'attempts_count' => $attemptsCount,
                'message' => $error,
                'sent_at' => $timestamp,
                'last_attempt_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );
    }

    private function enabledChannels(): array
    {
        return collect(config('workspace_trial_alerts.channels', []))
            ->filter(fn (array $channelConfig) => (bool) ($channelConfig['enabled'] ?? false))
            ->keys()
            ->values()
            ->all();
    }

    private function hasEnabledChannels(): bool
    {
        return $this->enabledChannels() !== [];
    }

    private function logKey(int $subscriptionId, string $trialEndsAt, string $eventType, string $channel, string $recipientKey): string
    {
        return implode('|', [$subscriptionId, $trialEndsAt, $eventType, $channel, $recipientKey]);
    }

    private function alertTimezone(): string
    {
        return (string) config('workspace_trial_alerts.timezone', 'Africa/Nairobi');
    }
}
