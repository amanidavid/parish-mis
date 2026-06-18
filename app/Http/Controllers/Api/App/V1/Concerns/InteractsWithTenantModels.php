<?php

namespace App\Http\Controllers\Api\App\V1\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait InteractsWithTenantModels
{
    /**
     * Apply prefix search.
     */
    protected function applyPrefixSearch(Builder $query, ?string $search, array $columns): Builder
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

    /**
     * Apply sort.
     */
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

    /**
     * Resolve model by uuid.
     */
    protected function resolveModelByUuid(string $modelClass, ?string $uuid): ?Model
    {
        if (empty($uuid)) {
            return null;
        }

        return $modelClass::query()->where('uuid', $uuid)->first();
    }
}
