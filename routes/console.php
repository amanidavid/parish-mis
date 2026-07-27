<?php

use App\Models\Landlord\AutomationTaskSetting;
use App\Models\Landlord\PropertyInvoice;
use App\Models\Tenancy\Tenant;
use App\Services\V1\Billing\PropertyInvoiceReminderResendService;
use App\Services\V1\Billing\PropertySubscriptionAutomationService;
use App\Services\V1\Billing\WorkspaceTrialAlertService;
use App\Services\V1\Billing\WorkspacePropertyRegistryService;
use App\Services\V1\Occupancy\ContractAlertService;
use App\Support\Tenancy\TenantConnectionManager;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

$tenantConnectionName = app(TenantConnectionManager::class)->connectionName();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tenants:migrate-existing {--tenant=} {--chunk=20}', function () use ($tenantConnectionName) {
    $tenantUuid = $this->option('tenant');
    $chunkSize = max((int) $this->option('chunk'), 1);

    $runMigration = function (Tenant $tenant) use ($tenantConnectionName): void {
        try {
            app(TenantConnectionManager::class)->activateTenant($tenant);

            $exitCode = Artisan::call('migrate', [
                '--path' => 'database/migrations/tenant',
                '--database' => $tenantConnectionName,
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(trim(Artisan::output()) ?: 'Migration failed');
            }

            $this->info(sprintf('[OK] %s | %s', $tenant->uuid, $tenant->database));
        } catch (Throwable $exception) {
            $this->error(sprintf('[FAIL] %s | %s | %s', $tenant->uuid, $tenant->database, $exception->getMessage()));
        } finally {
            app(TenantConnectionManager::class)->clearTenantContext();
        }
    };

    if ($tenantUuid) {
        $tenant = Tenant::query()
            ->where('uuid', $tenantUuid)
            ->where('provisioning_status', 'ready')
            ->firstOrFail();

        $runMigration($tenant);

        return self::SUCCESS;
    }

    Tenant::query()
        ->where('provisioning_status', 'ready')
        ->orderBy('id')
        ->chunkById($chunkSize, function ($tenants) use ($runMigration) {
            foreach ($tenants as $tenant) {
                $runMigration($tenant);
            }
        });

    return self::SUCCESS;
})->purpose('Run tenant migrations for existing ready tenant databases');

Artisan::command('tenants:seed-existing {--tenant=} {--chunk=20}', function () use ($tenantConnectionName) {
    $tenantUuid = $this->option('tenant');
    $chunkSize = max((int) $this->option('chunk'), 1);

    $runSeeder = function (Tenant $tenant) use ($tenantConnectionName): void {
        try {
            app(TenantConnectionManager::class)->activateTenant($tenant);

            $exitCode = Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Tenant\\TenantSeeder',
                '--database' => $tenantConnectionName,
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(trim(Artisan::output()) ?: 'Seeding failed');
            }

            $this->info(sprintf('[OK] %s | %s', $tenant->uuid, $tenant->database));
        } catch (Throwable $exception) {
            $this->error(sprintf('[FAIL] %s | %s | %s', $tenant->uuid, $tenant->database, $exception->getMessage()));
        } finally {
            app(TenantConnectionManager::class)->clearTenantContext();
        }
    };

    if ($tenantUuid) {
        $tenant = Tenant::query()
            ->where('uuid', $tenantUuid)
            ->where('provisioning_status', 'ready')
            ->firstOrFail();

        $runSeeder($tenant);

        return self::SUCCESS;
    }

    Tenant::query()
        ->where('provisioning_status', 'ready')
        ->orderBy('id')
        ->chunkById($chunkSize, function ($tenants) use ($runSeeder) {
            foreach ($tenants as $tenant) {
                $runSeeder($tenant);
            }
        });

    return self::SUCCESS;
})->purpose('Run tenant seeders for existing ready tenant databases');

Artisan::command('tenants:seed-property-types {--tenant=} {--chunk=20}', function () use ($tenantConnectionName) {
    $tenantUuid = $this->option('tenant');
    $chunkSize = max((int) $this->option('chunk'), 1);

    $runSeeder = function (Tenant $tenant) use ($tenantConnectionName): void {
        try {
            app(TenantConnectionManager::class)->activateTenant($tenant);

            $exitCode = Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\Tenant\\PropertyTypeSeeder',
                '--database' => $tenantConnectionName,
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(trim(Artisan::output()) ?: 'Property type seeding failed');
            }

            $this->info(sprintf('[OK] %s | %s', $tenant->uuid, $tenant->database));
        } catch (Throwable $exception) {
            $this->error(sprintf('[FAIL] %s | %s | %s', $tenant->uuid, $tenant->database, $exception->getMessage()));
        } finally {
            app(TenantConnectionManager::class)->clearTenantContext();
        }
    };

    if ($tenantUuid) {
        $tenant = Tenant::query()
            ->where('uuid', $tenantUuid)
            ->where('provisioning_status', 'ready')
            ->firstOrFail();

        $runSeeder($tenant);

        return self::SUCCESS;
    }

    Tenant::query()
        ->where('provisioning_status', 'ready')
        ->orderBy('id')
        ->chunkById($chunkSize, function ($tenants) use ($runSeeder) {
            foreach ($tenants as $tenant) {
                $runSeeder($tenant);
            }
        });

    return self::SUCCESS;
})->purpose('Run the tenant property type seeder for existing ready tenant databases');

Artisan::command('billing:sync-workspace-properties {--tenant=} {--chunk=20}', function () {
    $tenantUuid = $this->option('tenant');
    $chunkSize = max((int) $this->option('chunk'), 1);

    app(WorkspacePropertyRegistryService::class)->syncReadyTenants(
        $tenantUuid ? (string) $tenantUuid : null,
        $chunkSize,
        function (string $status, Tenant $tenant, int $rowsCount, ?string $message) {
            if ($status === 'ok') {
                $this->info(sprintf('[OK] %s | %s property rows synced', $tenant->uuid, $rowsCount));

                return;
            }

            $this->error(sprintf('[FAIL] %s | %s', $tenant->uuid, $message));
        }
    );

    return self::SUCCESS;
})->purpose('Sync landlord workspace property registry rows from ready tenant databases');

Artisan::command('billing:sync-property-subscription-statuses {--force}', function () {
    $force = (bool) $this->option('force');
    $service = app(PropertySubscriptionAutomationService::class);

    $run = $service->runTaskByKey(AutomationTaskSetting::TASK_PROPERTY_SUBSCRIPTION_EXPIRY_SYNC, $force);

    $this->info($run?->message ?? 'Property subscription status sync completed.');

    return self::SUCCESS;
})->purpose('Update expired property subscription statuses in the landlord billing ledger');

Artisan::command('contracts:sync-statuses {--force}', function () {
    $force = (bool) $this->option('force');
    $service = app(PropertySubscriptionAutomationService::class);

    $run = $service->runTaskByKey(AutomationTaskSetting::TASK_CUSTOMER_CONTRACT_EXPIRY_SYNC, $force);

    $this->info($run?->message ?? 'Customer contract lifecycle sync completed.');

    return self::SUCCESS;
})->purpose('Activate due draft contracts, expire ended contracts, and refresh occupancy across ready tenant databases');

Artisan::command('contracts:send-alerts {--tenant=} {--chunk=20}', function () {
    $tenantUuid = $this->option('tenant');
    $chunkSize = max((int) $this->option('chunk'), 1);

    $alertsSent = app(ContractAlertService::class)->syncReadyTenants(
        $tenantUuid ? (string) $tenantUuid : null,
        $chunkSize
    );

    $this->info(sprintf('Contract alert sync completed. Alerts sent: %d', $alertsSent));

    return self::SUCCESS;
})->purpose('Send expiring soon and expired contract alerts across ready tenant databases');

Artisan::command('billing:send-workspace-trial-alerts {--tenant=} {--chunk=100}', function () {
    $tenantUuid = $this->option('tenant');
    $chunkSize = max((int) $this->option('chunk'), 1);

    $alertsSent = app(WorkspaceTrialAlertService::class)->syncReadyTenants(
        $tenantUuid ? (string) $tenantUuid : null,
        $chunkSize
    );

    $this->info(sprintf('Workspace trial alert sync completed. Alerts sent: %d', $alertsSent));

    return self::SUCCESS;
})->purpose('Send expiring soon and expiry day workspace trial alerts to workspace owners');

Artisan::command('billing:run-automation', function () {
    $executed = app(PropertySubscriptionAutomationService::class)->runDueTasks();

    $this->info(sprintf('Billing automation evaluated successfully. Executed tasks: %d', $executed));

    return self::SUCCESS;
})->purpose('Run due billing automation tasks');

Artisan::command('invoices:resend-reminder {invoice_uuid?} {--invoice=*} {--delivery-log=*} {--channel=email} {--status=} {--source-channel=} {--search=} {--search-by=invoice_number} {--tenant=} {--date-from=} {--date-to=} {--limit=100} {--force=1}', function () {
    $invoiceUuids = collect(array_merge(
        $this->argument('invoice_uuid') ? [(string) $this->argument('invoice_uuid')] : [],
        (array) $this->option('invoice')
    ))
        ->filter()
        ->unique()
        ->values()
        ->all();

    $payload = array_filter([
        'channel' => (string) $this->option('channel'),
        'force' => filter_var($this->option('force'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
        'limit' => (int) $this->option('limit'),
        'invoice_uuids' => $invoiceUuids !== [] ? $invoiceUuids : null,
        'delivery_log_uuids' => ($deliveryLogs = collect((array) $this->option('delivery-log'))->filter()->unique()->values()->all()) !== [] ? $deliveryLogs : null,
        'delivery_status' => $this->option('status') ?: null,
        'source_channel' => $this->option('source-channel') ?: null,
        'search' => $this->option('search') ?: null,
        'search_by' => $this->option('search-by') ?: null,
        'tenant_uuid' => $this->option('tenant') ?: null,
        'date_from' => $this->option('date-from') ?: null,
        'date_to' => $this->option('date-to') ?: null,
    ], fn ($value) => $value !== null && $value !== '');

    $service = app(PropertyInvoiceReminderResendService::class);

    try {
        if (count($invoiceUuids) === 1 && !isset($payload['delivery_log_uuids']) && count($payload) <= 3) {
            $invoice = PropertyInvoice::query()
                ->where('uuid', $invoiceUuids[0])
                ->firstOrFail();

            $result = $service->queueInvoiceReminder(
                $invoice,
                (string) ($payload['channel'] ?? 'email'),
                (bool) ($payload['force'] ?? true)
            );
        } else {
            $result = $service->queueBulk($payload);
        }
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $this->info(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return self::SUCCESS;
})->purpose('Resend one or many property invoice reminders by channel, invoice selection, or indexed delivery-log filters');

Schedule::command('billing:run-automation')->everyMinute();
