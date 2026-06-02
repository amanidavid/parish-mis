<?php

namespace App\Services\V1;

use App\Jobs\ProvisionTenantWorkspace;
use App\Models\Landlord\BaseUser;
use App\Models\Landlord\UserTenant;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenancy\Tenant;
use App\Support\Tenancy\TenantConnectionManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class TenantProvisioningService
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private TenantConnectionManager $tenantConnectionManager
    )
    {
    }

    public function dispatchProvisioning(Tenant $tenant, int $ownerId, ?string $planUuid = null): void
    {
        ProvisionTenantWorkspace::dispatch($tenant->id, $ownerId, $planUuid)->afterCommit();
    }

    public function provision(int $tenantId, int $ownerId, ?string $planUuid = null): void
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $owner = BaseUser::query()->findOrFail($ownerId);
        $tenantConnectionName = $this->tenantConnectionManager->connectionName();
        $tenantDriver = (string) config(sprintf('database.connections.%s.driver', $tenantConnectionName), 'mysql');
        $originalTenant = Tenant::current();

        if ($tenant->provisioning_status === 'ready') {
            return;
        }

        $this->markProvisioning($tenant->id);
        $this->log($tenant->id, 'processing', 'starting', 'Tenant provisioning started', [
            'database' => $tenant->database,
            'owner_uuid' => $owner->uuid,
        ]);

        try {
            $this->createTenantDatabase($tenant->database, $tenantConnectionName, $tenantDriver);
            $this->log($tenant->id, 'processing', 'database_created', 'Tenant database is ready');

            $this->tenantConnectionManager->activateTenant($tenant);

            $this->assertTenantDatabaseSelected($tenant->database, $tenantConnectionName, $tenantDriver);

            $this->runArtisanCommand('migrate', [
                '--path' => 'database/migrations/tenant',
                '--database' => $tenantConnectionName,
                '--force' => true,
            ], $tenant->id, 'migrations');
            $this->log($tenant->id, 'processing', 'migrated', 'Tenant migrations completed');

            $this->runArtisanCommand('db:seed', [
                '--class' => 'Database\\Seeders\\Tenant\\TenantSeeder',
                '--database' => $tenantConnectionName,
                '--force' => true,
            ], $tenant->id, 'seeders');
            $this->log($tenant->id, 'processing', 'seeded', 'Tenant seeders completed');

            $tenantUser = TenantUser::on($tenantConnectionName)->firstOrCreate(
                ['base_user_id' => $owner->id],
                [
                    'uuid' => (string) Str::uuid(),
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'phone' => $owner->phone,
                    'status' => 'active',
                ]
            );

            if (!$tenantUser->hasRole('owner')) {
                $tenantUser->assignRole('owner');
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->subscriptionService->createTrialSubscriptionForTenant($tenant, $planUuid);

            DB::connection('base')->transaction(function () use ($tenant) {
                $freshTenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
                $freshTenant->forceFill([
                    'provisioning_status' => 'ready',
                    'provision_error' => null,
                    'provisioned_at' => now(),
                ])->save();
            });

            $this->log($tenant->id, 'success', 'completed', 'Tenant provisioning completed successfully');
        } catch (\Throwable $exception) {
            report($exception);

            Log::error('Tenant provisioning failed.', [
                'tenant_id' => $tenant->id,
                'tenant_uuid' => $tenant->uuid,
                'database' => $tenant->database,
                'owner_id' => $ownerId,
                'plan_uuid' => $planUuid,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            $this->markFailed($tenant->id, $exception->getMessage(), [
                'database' => $tenant->database,
                'owner_id' => $ownerId,
                'plan_uuid' => $planUuid,
                'exception_class' => $exception::class,
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        } finally {
            $this->tenantConnectionManager->restoreTenant($originalTenant);
        }
    }

    public function markFailed(int $tenantId, string $message, array $context = []): void
    {
        DB::connection('base')->transaction(function () use ($tenantId, $message) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenantId);
            $tenant->forceFill([
                'provisioning_status' => 'failed',
                'provision_error' => $message,
            ])->save();
        });

        $this->log($tenantId, 'failed', 'failed', $message, $context);
    }

    public function retry(Tenant $tenant, int $ownerId, ?string $planUuid = null): Tenant
    {
        DB::connection('base')->transaction(function () use ($tenant) {
            $freshTenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $freshTenant->forceFill([
                'provisioning_status' => 'pending',
                'provision_error' => null,
            ])->save();
        });

        $this->log($tenant->id, 'queued', 'retry_queued', 'Tenant provisioning retry queued');
        $this->dispatchProvisioning($tenant->fresh(), $ownerId, $planUuid);

        return $tenant->fresh();
    }

    private function markProvisioning(int $tenantId): void
    {
        DB::connection('base')->transaction(function () use ($tenantId) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenantId);
            $tenant->forceFill([
                'provisioning_status' => 'provisioning',
                'provision_error' => null,
                'provision_started_at' => $tenant->provision_started_at ?? now(),
                'provision_attempts' => ((int) $tenant->provision_attempts) + 1,
            ])->save();
        });
    }

    private function log(int $tenantId, string $status, string $step, ?string $message = null, array $context = []): void
    {
        DB::connection('base')->table('tenant_database_provision_logs')->insert([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'status' => $status,
            'step' => $step,
            'message' => $message,
            'context' => !empty($context) ? json_encode($context) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function runArtisanCommand(string $command, array $parameters, int $tenantId, string $step): void
    {
        $exitCode = Artisan::call($command, $parameters);

        if ($exitCode === 0) {
            return;
        }

        $output = trim(Artisan::output());

        $this->log($tenantId, 'failed', $step, sprintf('Tenant %s failed.', $step), [
            'command' => $command,
            'parameters' => $parameters,
            'exit_code' => $exitCode,
            'output' => $output !== '' ? $output : null,
        ]);

        throw new \RuntimeException(
            $output !== ''
                ? sprintf('Tenant %s failed: %s', $step, $output)
                : sprintf('Tenant %s failed with exit code %d.', $step, $exitCode)
        );
    }
    private function createTenantDatabase(string $databaseName, string $tenantConnectionName, string $tenantDriver): void
    {
        if ($tenantDriver === 'pgsql') {
            $exists = DB::connection('base')->selectOne(
                'select 1 as present from pg_database where datname = ? limit 1',
                [$databaseName]
            );

            if ($exists) {
                return;
            }

            $tenantOwner = (string) config(sprintf('database.connections.%s.username', $tenantConnectionName));
            $quotedDatabase = $this->quoteIdentifier($databaseName, $tenantDriver);
            $quotedOwner = $this->quoteIdentifier($tenantOwner, $tenantDriver);

            DB::connection('base')->statement(
                sprintf(
                    'CREATE DATABASE %s WITH OWNER = %s ENCODING = %s TEMPLATE template0',
                    $quotedDatabase,
                    $quotedOwner,
                    DB::connection('base')->getPdo()->quote('UTF8')
                )
            );

            return;
        }

        DB::connection('base')->statement(
                sprintf(
                    'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $this->quoteIdentifier($databaseName, $tenantDriver)
            )
        );
    }

    private function assertTenantDatabaseSelected(string $databaseName, string $tenantConnectionName, string $tenantDriver): void
    {
        $selected = $tenantDriver === 'pgsql'
            ? DB::connection($tenantConnectionName)->selectOne('select current_database() as db')
            : DB::connection($tenantConnectionName)->selectOne('select database() as db');

        if (!$selected || data_get($selected, 'db') !== $databaseName) {
            throw new \RuntimeException('Failed to select tenant database for migrations');
        }
    }

    private function quoteIdentifier(string $value, string $driver): string
    {
        if ($driver === 'pgsql') {
            return sprintf('"%s"', str_replace('"', '""', $value));
        }

        if ($driver === 'mysql') {
            return sprintf('`%s`', str_replace('`', '``', $value));
        }

        return sprintf('"%s"', str_replace('"', '""', $value));
    }
}
