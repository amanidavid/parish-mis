<?php

namespace App\Services\V1;

use App\Jobs\ProvisionTenantWorkspace;
use App\Models\Landlord\BaseUser;
use App\Models\Landlord\UserTenant;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenancy\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class TenantProvisioningService
{
    public function __construct(private SubscriptionService $subscriptionService)
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
        $originalTenantDatabase = config('database.connections.mysql.database');

        if ($tenant->provisioning_status === 'ready') {
            return;
        }

        $this->markProvisioning($tenant->id);
        $this->log($tenant->id, 'processing', 'starting', 'Tenant provisioning started', [
            'database' => $tenant->database,
            'owner_uuid' => $owner->uuid,
        ]);

        try {
            DB::connection('base')->statement(
                sprintf(
                    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    str_replace('`', '``', $tenant->database)
                )
            );
            $this->log($tenant->id, 'processing', 'database_created', 'Tenant database is ready');

            DB::purge('mysql');
            config(['database.connections.mysql.database' => $tenant->database]);
            DB::reconnect('mysql');

            DB::connection('mysql')->statement(
                sprintf('USE `%s`', str_replace('`', '``', $tenant->database))
            );

            $selected = DB::connection('mysql')->select('select database() as db');
            if (empty($selected) || (data_get($selected, '0.db') !== $tenant->database)) {
                throw new \RuntimeException('Failed to select tenant database for migrations');
            }

            $this->runArtisanCommand('migrate', [
                '--path' => 'database/migrations/tenant',
                '--database' => 'mysql',
                '--force' => true,
            ], $tenant->id, 'migrations');
            $this->log($tenant->id, 'processing', 'migrated', 'Tenant migrations completed');

            $this->runArtisanCommand('db:seed', [
                '--class' => 'Database\\Seeders\\Tenant\\TenantSeeder',
                '--database' => 'mysql',
                '--force' => true,
            ], $tenant->id, 'seeders');
            $this->log($tenant->id, 'processing', 'seeded', 'Tenant seeders completed');

            $tenant->makeCurrent();

            $tenantUser = TenantUser::query()->firstOrCreate(
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
            Tenant::forgetCurrent();
            DB::purge('mysql');
            config(['database.connections.mysql.database' => $originalTenantDatabase]);
            DB::reconnect('mysql');
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
}
