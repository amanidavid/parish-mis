<?php

namespace App\Http\Controllers\Api\Admin\V1\Concerns;

use App\Models\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;

trait InteractsWithTenantAdminModels
{
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

        if ($currentTenant?->is($tenant)) {
            return $callback();
        }

        $tenant->makeCurrent();

        try {
            return $callback();
        } finally {
            Tenant::forgetCurrent();

            if ($currentTenant) {
                $currentTenant->makeCurrent();
            }
        }
    }
}
