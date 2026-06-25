<?php

namespace App\Services\V1\Occupancy;

use App\Models\Tenancy\Tenant;
use App\Services\V1\Messaging\SmsService;
use App\Services\V1\TenantProvisioningService;
use App\Support\Tenancy\TenantConnectionManager;
use Illuminate\Support\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ContractAlertService
{
    private const EVENT_EXPIRING_SOON = 'expiring_soon';
    private const EVENT_EXPIRED = 'expired';

    private ?ConnectionInterface $tenantConnection = null;

    /**
     * Create a new instance.
     */
    public function __construct(
        private TenantConnectionManager $tenantConnectionManager,
        private ContractAlertRecipientResolver $recipientResolver,
        private SmsService $smsService,
        private TenantProvisioningService $tenantProvisioningService,
    ) {
    }

    /**
     * Sync ready tenants.
     */
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

    /**
     * Sync tenant.
     */
    public function syncTenant(Tenant $tenant): int
    {
        if ($tenant->provisioning_status !== 'ready' || empty($tenant->database)) {
            return 0;
        }

        return $this->runInTenantContext($tenant, fn () => $this->dispatchTenantAlerts());
    }

    /**
     * Sync tenant safely.
     */
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

    /**
     * Mark tenant failed if database missing.
     */
    private function markTenantFailedIfDatabaseMissing(Tenant $tenant, Throwable $exception): void
    {
        if (!$this->isMissingTenantDatabaseException($exception, $tenant)) {
            return;
        }

        Log::error(sprintf(
            'Workspace "%s" (%s) was marked failed during contract_alerts because its tenant database "%s" could not be found; verify the database exists and then retry provisioning.',
            $tenant->display_name ?: $tenant->name,
            $tenant->uuid,
            $tenant->database
        ));

        $this->tenantProvisioningService->markFailed(
            $tenant->id,
            'Workspace database is missing. Please retry provisioning or contact support.',
            [
                'database' => $tenant->database,
                'source' => 'contract_alerts',
                'exception_class' => $exception::class,
            ]
        );
    }

    /**
     * Determine whether missing tenant database exception.
     */
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

    /**
     * Dispatch tenant alerts.
     */
    private function dispatchTenantAlerts(): int
    {
        return $this->processContracts($this->expiringSoonContractsQuery(), self::EVENT_EXPIRING_SOON)
            + $this->processContracts($this->expiredContractsQuery(), self::EVENT_EXPIRED);
    }

    /**
     * Process contracts.
     */
    private function processContracts($query, string $eventType): int
    {
        $sentAlerts = 0;
        $timestamp = now();

        $query->orderBy('customer_contracts.id')
            ->chunkById(100, function ($contracts) use (&$sentAlerts, $eventType, $timestamp) {
                $propertyIds = $contracts->pluck('property_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
                $contractIds = $contracts->pluck('contract_id')->map(fn ($id) => (int) $id)->values()->all();
                $staffRecipients = config('contract_alerts.recipients.staff', true)
                    ? $this->recipientResolver->resolveForProperties($propertyIds)
                    : [];
                $existingLogs = $this->existingLogs($contractIds, $eventType);

                foreach ($contracts as $contract) {
                    [$subject, $message] = $this->buildMessage($contract, $eventType);
                    $contractRecipients = $this->resolveRecipientsForContract(
                        $contract,
                        $staffRecipients[(int) $contract->property_id] ?? []
                    );

                    foreach ($contractRecipients as $recipient) {
                        foreach ($this->enabledChannels() as $channel) {
                            $address = $channel === 'sms'
                                ? trim((string) ($recipient['phone'] ?? ''))
                                : trim((string) ($recipient['email'] ?? ''));

                            if ($address === '') {
                                continue;
                            }

                            $logKey = $this->logKey(
                                (int) $contract->contract_id,
                                (string) $contract->end_date,
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
                                $this->dispatchChannel($channel, $address, $subject, $message, $recipient, $contract, $eventType);
                                $sentAlerts++;
                            } catch (Throwable $exception) {
                                $status = 'failed';
                                $error = $exception->getMessage();
                            }

                            $existingUuid = $existingLogs[$logKey]['uuid'] ?? null;
                            $this->upsertLog($contract, $recipient, $channel, $eventType, $address, $status, $error, $timestamp, $existingUuid);
                            $existingLogs[$logKey] = [
                                'status' => $status,
                                'uuid' => $existingUuid ?? $existingLogs[$logKey]['uuid'] ?? null,
                            ];
                        }
                    }
                }
            }, 'customer_contracts.id', 'contract_id');

        return $sentAlerts;
    }

    /**
     * Resolve recipients for contract.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveRecipientsForContract(object $contract, array $staffRecipients): array
    {
        $recipients = [];

        if ((bool) config('contract_alerts.recipients.occupant', true)) {
            $occupantRecipient = [
                'recipient_type' => 'occupant',
                'recipient_key' => 'occupant:'.$contract->customer_id,
                'name' => $contract->occupant_name,
                'email' => $contract->occupant_email,
                'phone' => $contract->occupant_phone,
            ];

            if (!blank($occupantRecipient['email']) || !blank($occupantRecipient['phone'])) {
                $recipients[] = $occupantRecipient;
            }
        }

        foreach ($staffRecipients as $staffRecipient) {
            $recipients[] = $staffRecipient;
        }

        return $recipients;
    }

    /**
     * Enabled channels.
     *
     * @return array<int, string>
     */
    private function enabledChannels(): array
    {
        return collect(config('contract_alerts.channels', []))
            ->filter(fn (array $channelConfig) => (bool) ($channelConfig['enabled'] ?? false))
            ->keys()
            ->values()
            ->all();
    }

    /**
     * Determine whether channels enabled.
     */
    private function hasEnabledChannels(): bool
    {
        return $this->enabledChannels() !== [];
    }

    /**
     * Expiring soon contracts query.
     */
    private function expiringSoonContractsQuery()
    {
        $targetDate = Carbon::today($this->alertTimezone())
            ->addDays((int) config('contract_alerts.warning_days', 7))
            ->toDateString();

        return $this->baseContractQuery()
            ->whereIn('customer_contracts.status', CustomerContractRuleService::ACTIVE_OCCUPANCY_CONTRACT_STATUSES)
            ->where('customer_contracts.end_date', '=', $targetDate);
    }

    /**
     * Expired contracts query.
     */
    private function expiredContractsQuery()
    {
        $today = Carbon::today($this->alertTimezone())->toDateString();

        return $this->baseContractQuery()
            ->where('customer_contracts.status', 'expired')
            ->where('customer_contracts.end_date', '=', $today);
    }

    /**
     * Base contract query.
     */
    private function baseContractQuery()
    {
        return $this->tenantDb()->table('customer_contracts')
            ->join('customers', 'customers.id', '=', 'customer_contracts.customer_id')
            ->leftJoin('customer_business_details', 'customer_business_details.customer_id', '=', 'customers.id')
            ->join('units', 'units.id', '=', 'customer_contracts.unit_id')
            ->join('property_floors', 'property_floors.id', '=', 'units.property_floor_id')
            ->join('properties', 'properties.id', '=', 'property_floors.property_id')
            ->select([
                'customer_contracts.id as contract_id',
                'customer_contracts.uuid as contract_uuid',
                'customer_contracts.customer_id',
                'customer_contracts.contract_number',
                'customer_contracts.end_date',
                'customers.email as occupant_email',
                'units.unit_number',
                'properties.id as property_id',
                'properties.uuid as property_uuid',
                'properties.name as property_name',
            ])
            ->selectRaw('COALESCE(customer_business_details.contact_person_name, customers.display_name) as occupant_name')
            ->selectRaw('COALESCE(customer_business_details.contact_person_phone, customers.phone) as occupant_phone')
            ->whereNotNull('customer_contracts.end_date');
    }

    /**
     * Build message.
     *
     * @return array{0: string, 1: string}
     */
    private function buildMessage(object $contract, string $eventType): array
    {
        $endDate = Carbon::parse((string) $contract->end_date)->format('Y-m-d');
        $subject = $eventType === self::EVENT_EXPIRING_SOON
            ? 'Contract Expiring Soon'
            : 'Contract Expired';

        $message = $eventType === self::EVENT_EXPIRING_SOON
            ? sprintf(
                'Contract %s for %s in unit %s at %s will expire on %s.',
                $contract->contract_number,
                $contract->occupant_name,
                $contract->unit_number,
                $contract->property_name,
                $endDate
            )
            : sprintf(
                'Contract %s for %s in unit %s at %s expired on %s.',
                $contract->contract_number,
                $contract->occupant_name,
                $contract->unit_number,
                $contract->property_name,
                $endDate
            );

        return [$subject, $message];
    }

    /**
     * Dispatch channel.
     */
    private function dispatchChannel(
        string $channel,
        string $address,
        string $subject,
        string $message,
        array $recipient,
        object $contract,
        string $eventType
    ): void {
        if ($channel === 'sms') {
            $this->smsService->sendText($address, $message, null, [
                'type' => 'contract_alert',
                'event' => $eventType,
                'contract_uuid' => $contract->contract_uuid,
                'property_uuid' => $contract->property_uuid,
                'recipient_type' => $recipient['recipient_type'],
            ]);

            return;
        }

        Mail::raw($message, function ($mail) use ($address, $recipient, $subject) {
            $mail->to($address, (string) ($recipient['name'] ?? 'Recipient'))
                ->subject($subject);
        });
    }

    /**
     * Existing logs.
     *
     * @return array<string, array<string, mixed>>
     */
    private function existingLogs(array $contractIds, string $eventType): array
    {
        if ($contractIds === []) {
            return [];
        }

        $logs = $this->tenantDb()->table('contract_alert_logs')
            ->whereIn('contract_id', $contractIds)
            ->where('event_type', $eventType)
            ->get();

        $mapped = [];
        foreach ($logs as $log) {
            $mapped[$this->logKey(
                (int) $log->contract_id,
                (string) $log->contract_end_date,
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

    /**
     * Upsert log.
     */
    private function upsertLog(
        object $contract,
        array $recipient,
        string $channel,
        string $eventType,
        string $address,
        string $status,
        ?string $error,
        Carbon $timestamp,
        ?string $existingUuid = null
    ): void {
        $this->tenantDb()->table('contract_alert_logs')->updateOrInsert(
            [
                'contract_id' => $contract->contract_id,
                'event_type' => $eventType,
                'channel' => $channel,
                'recipient_key' => $recipient['recipient_key'],
                'contract_end_date' => $contract->end_date,
            ],
            [
                'uuid' => $existingUuid ?: (string) str()->uuid(),
                'contract_uuid' => $contract->contract_uuid,
                'customer_id' => $contract->customer_id,
                'property_id' => $contract->property_id,
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

    /**
     * Log key.
     */
    private function logKey(int $contractId, string $contractEndDate, string $eventType, string $channel, string $recipientKey): string
    {
        return implode('|', [$contractId, $contractEndDate, $eventType, $channel, $recipientKey]);
    }

    /**
     * Alert timezone.
     */
    private function alertTimezone(): string
    {
        return (string) config('contract_alerts.timezone', 'Africa/Nairobi');
    }

    /**
     * Run in tenant context.
     */
    private function runInTenantContext(Tenant $tenant, callable $callback): mixed
    {
        $currentTenant = Tenant::current();
        $this->tenantConnectionManager->activateTenant($tenant);
        $this->tenantConnection = $this->tenantDb();

        try {
            return $callback();
        } finally {
            $this->tenantConnection = null;
            $this->tenantConnectionManager->restoreTenant($currentTenant);
        }
    }

    /**
     * Tenant database connection.
     */
    private function tenantDb(): ConnectionInterface
    {
        return $this->tenantConnection
            ?? DB::connection($this->tenantConnectionManager->connectionName());
    }
}
