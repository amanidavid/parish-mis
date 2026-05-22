<?php

namespace App\Http\Controllers\Api\App\V1\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait InteractsWithTenantModels
{
    protected function applySort(
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

    protected function resolveModelByUuid(string $modelClass, ?string $uuid): ?Model
    {
        if (empty($uuid)) {
            return null;
        }

        return $modelClass::query()->where('uuid', $uuid)->first();
    }
}
