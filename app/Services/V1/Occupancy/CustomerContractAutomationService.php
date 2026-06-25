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
        private CustomerContractRuleService $customerContractRuleService,
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
            $timestamp = now();

            return $connection->transaction(function () use ($connection, $today, $timestamp) {
                $expiredContractRows = $connection->table('customer_contracts')
                    ->select(['customer_id', 'unit_id'])
                    ->where('status', 'active')
                    ->whereNotNull('end_date')
                    ->where('end_date', '<', $today)
                    ->get();

                $startingTodayRows = $connection->table('customer_contracts')
                    ->select(['customer_id', 'unit_id'])
                    ->where('status', 'active')
                    ->where('start_date', '=', $today)
                    ->where(function ($query) use ($today) {
                        $query
                            ->whereNull('end_date')
                            ->orWhere('end_date', '>=', $today);
                    })
                    ->get();

                $affectedCustomerIds = $expiredContractRows
                    ->pluck('customer_id')
                    ->merge($startingTodayRows->pluck('customer_id'))
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                $affectedUnitIds = $expiredContractRows
                    ->pluck('unit_id')
                    ->merge($startingTodayRows->pluck('unit_id'))
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                $expiredContracts = $connection->table('customer_contracts')
                    ->where('status', 'active')
                    ->whereNotNull('end_date')
                    ->where('end_date', '<', $today)
                    ->update([
                        'status' => 'expired',
                        'updated_at' => $timestamp,
                    ]);

                $unitStatusUpdates = $this->customerContractRuleService->syncUnitOccupancyStatuses($affectedUnitIds);
                $customerStatusUpdates = $this->customerContractRuleService->syncCustomerStatuses($affectedCustomerIds);

                return $expiredContracts + $unitStatusUpdates + $customerStatusUpdates;
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
            throw new InvalidArgumentException('Contract automation cannot run because workspace setup is not complete.');
        }
    }
}
