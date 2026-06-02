<?php

namespace App\Support\Tenancy;

use App\Models\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

class TenantConnectionManager
{
    public function activateTenant(Tenant $tenant): void
    {
        $tenant->makeCurrent();
        $this->reconnectToDatabase($tenant->database);
    }

    public function restoreTenant(?Tenant $tenant): void
    {
        if ($tenant) {
            $tenant->makeCurrent();
            $this->reconnectToDatabase($tenant->database);

            return;
        }

        $this->restoreBootstrapConnection();
    }

    public function clearTenantContext(): void
    {
        Tenant::forgetCurrent();
        $this->restoreBootstrapConnection();
    }

    public function reconnectToDatabase(?string $databaseName): void
    {
        $tenantConnectionName = $this->connectionName();

        config([
            sprintf('database.connections.%s.database', $tenantConnectionName) => $databaseName,
        ]);

        app('db')->extend($tenantConnectionName, function (array $config, string $name) use ($databaseName) {
            $config['database'] = $databaseName;

            return app('db.factory')->make($config, $name);
        });

        DB::purge($tenantConnectionName);

        if (!empty($databaseName)) {
            DB::reconnect($tenantConnectionName);
        }
    }

    public function restoreBootstrapConnection(): void
    {
        $this->reconnectToDatabase($this->bootstrapDatabase());
    }

    public function connectionName(): string
    {
        return (string) config('multitenancy.tenant_database_connection_name', 'tenant');
    }

    public function bootstrapDatabase(): ?string
    {
        return config(sprintf('database.connections.%s.bootstrap_database', $this->connectionName()));
    }
}
