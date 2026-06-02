<?php

namespace App\Http\Controllers\Api\Admin\V1\Concerns;

use App\Models\Tenancy\Tenant;
use App\Support\Tenancy\TenantConnectionManager;
use Illuminate\Database\Eloquent\Builder;

trait InteractsWithTenantAdminModels
{
    protected function applyTenantPrefixSearch(Builder $query, ?string $search, array $columns): Builder
    {
        $search = trim((string) $search);

        if ($search === '' || $columns === []) {
            return $query;
        }

        return $query->where(function (Builder $innerQuery) use ($columns, $search) {
            foreach ($columns as $column) {
                $innerQuery->orWhere($column, 'like', $search.'%');
            }
        });
    }

    protected function applyTenantSort(
        Builder $query,
        ?string $sort,
        array $allowed,
        string $defaultColumn = 'created_at',
        string $defaultDirection = 'desc'
    ): Builder {
        if (empty($sort)) {
            return $query->orderBy($defaultColumn, $defaultDirection);
        }

        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (!in_array($column, $allowed, true)) {
            return $query->orderBy($defaultColumn, $defaultDirection);
        }

        return $query->orderBy($column, $direction);
    }

    protected function runInTenantContext(Tenant $tenant, callable $callback): mixed
    {
        $currentTenant = Tenant::current();
        $tenantConnectionManager = app(TenantConnectionManager::class);

        if ($currentTenant?->is($tenant)) {
            $tenantConnectionManager->activateTenant($tenant);

            return $callback();
        }

        $tenantConnectionManager->activateTenant($tenant);

        try {
            return $callback();
        } finally {
            $tenantConnectionManager->restoreTenant($currentTenant);
        }
    }

    protected function tenantConnectionName(): string
    {
        return app(TenantConnectionManager::class)->connectionName();
    }
}
