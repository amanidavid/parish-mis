<?php

namespace App\Services\V1\Occupancy;

use App\Models\Tenancy\Tenant;
use App\Services\V1\TenantProvisioningService;
use App\Support\Tenancy\TenantConnectionManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Throwable;

class CustomerContractAutomationService
{
    /**
     * Create a new instance.
     */
    public function __construct(
        private TenantConnectionManager $tenantConnectionManager,
        private TenantProvisioningService $tenantProvisioningService,
    ) {
    }

    /**
     * Sync ready tenants.
     */
    public function syncReadyTenants(?string $tenantUuid = null, int $chunk = 20): int
    {
        $chunk = max($chunk, 1);
        $updatedRows = 0;

        $query = Tenant::query()
            ->where('provisioning_status', 'ready')
            ->orderBy('id');

        if ($tenantUuid) {
            $tenant = (clone $query)->where('uuid', $tenantUuid)->firstOrFail();

            return $this->syncTenantSafely($tenant);
        }

        $query->chunkById($chunk, function ($tenants) use (&$updatedRows) {
            foreach ($tenants as $tenant) {
                $updatedRows += $this->syncTenantSafely($tenant);
            }
        });

        return $updatedRows;
    }

    /**
     * Sync tenant.
     */
    public function syncTenant(Tenant $tenant): int
    {
        $this->assertTenantReady($tenant);

        return $this->runInTenantContext($tenant, function () {
            $today = Carbon::today()->toDateString();
            $connection = DB::connection($this->tenantConnectionManager->connectionName());

            return $connection->transaction(function () use ($connection, $today) {
                $expiredContracts = $connection->table('customer_contracts')
                    ->where('status', 'active')
                    ->whereNotNull('end_date')
                    ->where('end_date', '<', $today)
                    ->update([
                        'status' => 'expired',
                        'updated_at' => now(),
                    ]);

                $occupiedUnits = $connection->table('units')
                    ->where('status', '!=', 'occupied')
                    ->whereExists(function ($query) use ($today) {
                        $query->selectRaw('1')
                            ->from('customer_contracts')
                            ->whereColumn('customer_contracts.unit_id', 'units.id')
                            ->whereIn('customer_contracts.status', CustomerContractRuleService::ACTIVE_OCCUPANCY_CONTRACT_STATUSES)
                            ->whereDate('customer_contracts.start_date', '<=', $today)
                            ->where(function ($innerQuery) use ($today) {
                                $innerQuery
                                    ->whereNull('customer_contracts.end_date')
                                    ->orWhereDate('customer_contracts.end_date', '>=', $today);
                            });
                    })
                    ->update([
                        'status' => 'occupied',
                        'updated_at' => now(),
                    ]);

                $vacantUnits = $connection->table('units')
                    ->where('status', 'occupied')
                    ->whereNotExists(function ($query) use ($today) {
                        $query->selectRaw('1')
                            ->from('customer_contracts')
                            ->whereColumn('customer_contracts.unit_id', 'units.id')
                            ->whereIn('customer_contracts.status', CustomerContractRuleService::ACTIVE_OCCUPANCY_CONTRACT_STATUSES)
                            ->whereDate('customer_contracts.start_date', '<=', $today)
                            ->where(function ($innerQuery) use ($today) {
                                $innerQuery
                                    ->whereNull('customer_contracts.end_date')
                                    ->orWhereDate('customer_contracts.end_date', '>=', $today);
                            });
                    })
                    ->update([
                        'status' => 'vacant',
                        'updated_at' => now(),
                    ]);

                return $expiredContracts + $occupiedUnits + $vacantUnits;
            });
        });
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
            'Workspace "%s" (%s) was marked failed during customer_contract_expiry_sync because its tenant database "%s" could not be found; verify the database exists and then retry provisioning.',
            $tenant->display_name ?: $tenant->name,
            $tenant->uuid,
            $tenant->database
        ));

        $this->tenantProvisioningService->markFailed(
            $tenant->id,
            'Workspace database is missing. Please retry provisioning or contact support.',
            [
                'database' => $tenant->database,
                'source' => 'customer_contract_expiry_sync',
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
     * Run in tenant context.
     */
    private function runInTenantContext(Tenant $tenant, callable $callback): mixed
    {
        $currentTenant = Tenant::current();
        $this->tenantConnectionManager->activateTenant($tenant);

        try {
            return $callback();
        } finally {
            $this->tenantConnectionManager->restoreTenant($currentTenant);
        }
    }

    /**
     * Assert tenant ready.
     */
    private function assertTenantReady(Tenant $tenant): void
    {
        if ($tenant->provisioning_status !== 'ready' || empty($tenant->database)) {
            throw new InvalidArgumentException('This workspace is not provisioned yet, so contract automation cannot run.');
        }
    }
}
