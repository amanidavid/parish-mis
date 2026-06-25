<?php

namespace App\Services\V1\Billing;

use App\Models\Landlord\PropertySubscription;
use App\Models\Tenancy\Tenant;
use App\Services\V1\Messaging\SmsService;
use App\Services\V1\Occupancy\ContractAlertRecipientResolver;
use App\Services\V1\TenantProvisioningService;
use App\Support\Tenancy\TenantConnectionManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PropertySubAlertService
{
    private const EVENT_EXPIRING_SOON = 'expiring_soon';
    private const EVENT_EXPIRES_TODAY = 'expires_today';

    private ?ConnectionInterface $tenantConnection = null;

    public function __construct(
        private TenantConnectionManager $tenantConnectionManager,
        private ContractAlertRecipientResolver $recipientResolver,
        private SmsService $smsService,
        private TenantProvisioningService $tenantProvisioningService,
    ) {
    }

    public function syncReadyTenants(?string $tenantUuid = null, int $chunk = 20): int
    {
        if (!$this->hasEnabledChannels()) {
            return 0;
        }

        $chunk = max($chunk, 1);
        $sentAlerts = 0;

        $query = Tenant::query()
            ->where('provisioning_status', 'ready')
            ->orderBy('id');

        if ($tenantUuid) {
            $tenant = (clone $query)->where('uuid', $tenantUuid)->firstOrFail();

            return $this->syncTenantSafely($tenant);
        }

        $query->chunkById($chunk, function ($tenants) use (&$sentAlerts) {
            foreach ($tenants as $tenant) {
                $sentAlerts += $this->syncTenantSafely($tenant);
            }
        });

        return $sentAlerts;
    }

    public function syncTenant(Tenant $tenant): int
    {
        if ($tenant->provisioning_status !== 'ready' || empty($tenant->database)) {
            return 0;
        }

        return $this->runInTenantContext($tenant, fn () => $this->dispatchTenantAlerts($tenant));
    }

    private function syncTenantSafely(Tenant $tenant): int
    {
        try {
            return $this->syncTenant($tenant);
        } catch (Throwable $exception) {
            report($exception);
            $this->markTenantFailedIfDatabaseMissing($tenant, $exception);

            return 0;
        }
    }

    private function dispatchTenantAlerts(Tenant $tenant): int
    {
        return $this->processSubscriptions($tenant, $this->expiringSoonSubscriptionsQuery($tenant), self::EVENT_EXPIRING_SOON)
            + $this->processSubscriptions($tenant, $this->expiresTodaySubscriptionsQuery($tenant), self::EVENT_EXPIRES_TODAY);
    }

    private function processSubscriptions(Tenant $tenant, $query, string $eventType): int
    {
        $sentAlerts = 0;
        $timestamp = now();

        $query->orderBy('property_subscriptions.id')
            ->chunkById(100, function ($subscriptions) use (&$sentAlerts, $tenant, $eventType, $timestamp) {
                $propertyIdMap = $this->tenantPropertyIdMap($tenant, $subscriptions->pluck('property_uuid')->all());
                $tenantPropertyIds = collect($propertyIdMap)
                    ->filter()
                    ->values()
                    ->all();
                $enabledChannels = $this->enabledChannels();
                $staffRecipients = $this->recipientResolver->resolveForPropertiesWithPermissions(
                    $tenantPropertyIds,
                    (array) config('property_subscription_alerts.staff_permissions', [])
                );
                $existingLogs = $this->existingLogs(
                    $subscriptions->pluck('subscription_id')->map(fn ($id) => (int) $id)->all(),
                    $eventType
                );

                foreach ($subscriptions as $subscription) {
                    $tenantPropertyId = $propertyIdMap[$subscription->property_uuid] ?? null;
                    if (!$tenantPropertyId) {
                        continue;
                    }

                    [$subject, $message] = $this->buildMessage($subscription, $eventType);

                    foreach (($staffRecipients[(int) $tenantPropertyId] ?? []) as $recipient) {
                        foreach ($enabledChannels as $channel) {
                            $address = $channel === 'sms'
                                ? trim((string) ($recipient['phone'] ?? ''))
                                : trim((string) ($recipient['email'] ?? ''));

                            if ($address === '') {
                                continue;
                            }

                            $logKey = $this->logKey(
                                (int) $subscription->subscription_id,
                                (string) $subscription->current_period_ends_on,
                                $eventType,
                                $channel,
                                $recipient['recipient_key']
                            );

                            if (($existingLogs[$logKey]['status'] ?? null) === 'success') {
                                continue;
                            }

                            $status = 'success';
                            $error = null;

                            try {
                                $this->dispatchChannel($channel, $address, $subject, $message, $recipient, $subscription, $eventType);
                                $sentAlerts++;
                            } catch (Throwable $exception) {
                                $status = 'failed';
                                $error = $exception->getMessage();
                            }

                            $existingUuid = $existingLogs[$logKey]['uuid'] ?? null;
                            $this->upsertLog($tenant, $subscription, $recipient, $channel, $eventType, $address, $status, $error, $timestamp, $existingUuid);
                            $existingLogs[$logKey] = [
                                'uuid' => $existingUuid,
                                'status' => $status,
                            ];
                        }
                    }
                }
            }, 'property_subscriptions.id', 'subscription_id');

        return $sentAlerts;
    }

    private function expiringSoonSubscriptionsQuery(Tenant $tenant)
    {
        $targetDate = Carbon::today($this->alertTimezone())
            ->addDays((int) config('property_subscription_alerts.warning_days', 7))
            ->toDateString();

        return $this->baseSubscriptionQuery($tenant)
            ->where('property_subscriptions.status', PropertySubscription::STATUS_ACTIVE)
            ->where('property_subscriptions.current_period_ends_on', '=', $targetDate);
    }

    private function expiresTodaySubscriptionsQuery(Tenant $tenant)
    {
        $today = Carbon::today($this->alertTimezone())->toDateString();

        return $this->baseSubscriptionQuery($tenant)
            ->where('property_subscriptions.status', PropertySubscription::STATUS_ACTIVE)
            ->where('property_subscriptions.current_period_ends_on', '=', $today);
    }

    private function baseSubscriptionQuery(Tenant $tenant)
    {
        return DB::connection('base')->table('property_subscriptions')
            ->join('workspace_properties', 'workspace_properties.id', '=', 'property_subscriptions.workspace_property_id')
            ->select([
                'property_subscriptions.id as subscription_id',
                'property_subscriptions.uuid as subscription_uuid',
                'property_subscriptions.workspace_property_id',
                'property_subscriptions.current_period_ends_on',
                'workspace_properties.property_uuid',
                'workspace_properties.property_name',
            ])
            ->where('property_subscriptions.tenant_id', $tenant->id)
            ->where('workspace_properties.tenant_id', $tenant->id)
            ->whereNull('workspace_properties.property_deleted_at')
            ->whereNotNull('property_subscriptions.current_period_ends_on');
    }

    private function tenantPropertyIdMap(Tenant $tenant, array $propertyUuids): array
    {
        $propertyUuids = collect($propertyUuids)->filter()->unique()->values()->all();

        if ($propertyUuids === []) {
            return [];
        }

        return $this->tenantDb()->table('properties')
            ->whereIn('uuid', $propertyUuids)
            ->pluck('id', 'uuid')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function buildMessage(object $subscription, string $eventType): array
    {
        $endDate = Carbon::parse((string) $subscription->current_period_ends_on)->format('Y-m-d');
        $subject = $eventType === self::EVENT_EXPIRING_SOON
            ? 'Property Subscription Expiring Soon'
            : 'Property Subscription Ends Today';

        $message = $eventType === self::EVENT_EXPIRING_SOON
            ? sprintf(
                'Property subscription for %s will expire on %s. Please renew before the coverage ends.',
                $subscription->property_name,
                $endDate
            )
            : sprintf(
                'Property subscription for %s ends today on %s. Please renew to avoid access interruption.',
                $subscription->property_name,
                $endDate
            );

        return [$subject, $message];
    }

    private function dispatchChannel(
        string $channel,
        string $address,
        string $subject,
        string $message,
        array $recipient,
        object $subscription,
        string $eventType
    ): void {
        if ($channel === 'sms') {
            $this->smsService->sendText($address, $message, null, [
                'type' => 'property_subscription_alert',
                'event' => $eventType,
                'subscription_uuid' => $subscription->subscription_uuid,
                'property_uuid' => $subscription->property_uuid,
                'recipient_type' => $recipient['recipient_type'],
            ]);

            return;
        }

        Mail::raw($message, function ($mail) use ($address, $recipient, $subject) {
            $mail->to($address, (string) ($recipient['name'] ?? 'Recipient'))
                ->subject($subject);
        });
    }

    private function existingLogs(array $subscriptionIds, string $eventType): array
    {
        if ($subscriptionIds === []) {
            return [];
        }

        $logs = DB::connection('base')->table('property_subscription_alert_logs')
            ->whereIn('property_subscription_id', $subscriptionIds)
            ->where('event_type', $eventType)
            ->get();

        $mapped = [];
        foreach ($logs as $log) {
            $mapped[$this->logKey(
                (int) $log->property_subscription_id,
                (string) $log->period_ends_on,
                $log->event_type,
                $log->channel,
                $log->recipient_key
            )] = [
                'uuid' => $log->uuid,
                'status' => $log->status,
            ];
        }

        return $mapped;
    }

    private function upsertLog(
        Tenant $tenant,
        object $subscription,
        array $recipient,
        string $channel,
        string $eventType,
        string $address,
        string $status,
        ?string $error,
        Carbon $timestamp,
        ?string $existingUuid = null
    ): void {
        DB::connection('base')->table('property_subscription_alert_logs')->updateOrInsert(
            [
                'tenant_id' => $tenant->id,
                'property_subscription_id' => $subscription->subscription_id,
                'event_type' => $eventType,
                'channel' => $channel,
                'recipient_key' => $recipient['recipient_key'],
                'period_ends_on' => $subscription->current_period_ends_on,
            ],
            [
                'uuid' => $existingUuid ?: (string) str()->uuid(),
                'property_subscription_uuid' => $subscription->subscription_uuid,
                'workspace_property_id' => $subscription->workspace_property_id,
                'property_uuid' => $subscription->property_uuid,
                'recipient_type' => $recipient['recipient_type'],
                'recipient_name' => $recipient['name'] ?? null,
                'recipient_address' => $address,
                'status' => $status,
                'message' => $error,
                'sent_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );
    }

    private function enabledChannels(): array
    {
        return collect(config('property_subscription_alerts.channels', []))
            ->filter(fn (array $channelConfig) => (bool) ($channelConfig['enabled'] ?? false))
            ->keys()
            ->values()
            ->all();
    }

    private function hasEnabledChannels(): bool
    {
        return $this->enabledChannels() !== [];
    }

    private function logKey(int $subscriptionId, string $periodEndsOn, string $eventType, string $channel, string $recipientKey): string
    {
        return implode('|', [$subscriptionId, $periodEndsOn, $eventType, $channel, $recipientKey]);
    }

    private function alertTimezone(): string
    {
        return (string) config('property_subscription_alerts.timezone', 'Africa/Nairobi');
    }

    private function runInTenantContext(Tenant $tenant, callable $callback): mixed
    {
        $currentTenant = Tenant::current();
        $this->tenantConnectionManager->activateTenant($tenant);
        $this->tenantConnection = DB::connection($this->tenantConnectionManager->connectionName());

        try {
            return $callback();
        } finally {
            $this->tenantConnection = null;
            $this->tenantConnectionManager->restoreTenant($currentTenant);
        }
    }

    private function tenantDb(): ConnectionInterface
    {
        return $this->tenantConnection
            ?? DB::connection($this->tenantConnectionManager->connectionName());
    }

    private function markTenantFailedIfDatabaseMissing(Tenant $tenant, Throwable $exception): void
    {
        if (!$this->isMissingTenantDatabaseException($exception, $tenant)) {
            return;
        }

        Log::error(sprintf(
            'Workspace "%s" (%s) was marked failed during property_subscription_alerts because its tenant database "%s" could not be found; verify the database exists and then retry provisioning.',
            $tenant->display_name ?: $tenant->name,
            $tenant->uuid,
            $tenant->database
        ));

        $this->tenantProvisioningService->markFailed(
            $tenant->id,
            'Workspace database is missing. Please retry provisioning or contact support.',
            [
                'database' => $tenant->database,
                'source' => 'property_subscription_alerts',
                'exception_class' => $exception::class,
            ]
        );
    }

    private function isMissingTenantDatabaseException(Throwable $exception, Tenant $tenant): bool
    {
        $message = strtolower($exception->getMessage());
        $database = strtolower((string) $tenant->database);

        if ($exception instanceof QueryException && str_contains($message, 'sqlstate[08006]')) {
            return str_contains($message, 'does not exist') && ($database === '' || str_contains($message, $database));
        }

        return str_contains($message, 'database')
            && str_contains($message, 'does not exist')
            && ($database === '' || str_contains($message, $database));
    }
}
