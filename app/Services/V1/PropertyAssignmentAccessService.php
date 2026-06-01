<?php

namespace App\Services\V1;

use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerContract;
use App\Models\Tenant\Permission;
use App\Models\Tenant\Property;
use App\Models\Tenant\PropertyFloor;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Builder;

class PropertyAssignmentAccessService
{
    public const BYPASS_PERMISSION = 'properties.scope.all';

    /**
     * @var array<int, array<int>>
     */
    private array $assignedPropertyIdsCache = [];

    private ?bool $bypassPermissionExists = null;

    public function canBypassPropertyScope(User $user): bool
    {
        if (!$this->bypassPermissionExists()) {
            return false;
        }

        return $user->hasPermissionTo(self::BYPASS_PERMISSION);
    }

    private function bypassPermissionExists(): bool
    {
        if ($this->bypassPermissionExists === null) {
            $this->bypassPermissionExists = \Illuminate\Support\Facades\Cache::remember(
                'permission_exists:' . self::BYPASS_PERMISSION,
                3600,
                fn () => Permission::query()
                    ->where('guard_name', 'api')
                    ->where('name', self::BYPASS_PERMISSION)
                    ->exists()
            );
        }

        return $this->bypassPermissionExists;
    }

    /**
     * @return array<int>
     */
    public function assignedPropertyIds(User $user): array
    {
        if ($this->canBypassPropertyScope($user)) {
            return [];
        }

        if (!array_key_exists($user->id, $this->assignedPropertyIdsCache)) {
            $this->assignedPropertyIdsCache[$user->id] = $user->staffPropertyAssignments()
                ->pluck('property_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        return $this->assignedPropertyIdsCache[$user->id];
    }

    public function userCanAccessProperty(User $user, int $propertyId): bool
    {
        if ($this->canBypassPropertyScope($user)) {
            return true;
        }

        return in_array($propertyId, $this->assignedPropertyIds($user), true);
    }

    public function scopeProperties(Builder $query, User $user, string $column = 'properties.id'): Builder
    {
        if ($this->canBypassPropertyScope($user)) {
            return $query;
        }

        $ids = $this->assignedPropertyIds($user);

        return $ids === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($column, $ids);
    }

    public function scopePropertyFloors(Builder $query, User $user, string $column = 'property_id'): Builder
    {
        if ($this->canBypassPropertyScope($user)) {
            return $query;
        }

        $ids = $this->assignedPropertyIds($user);

        return $ids === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($column, $ids);
    }

    public function scopeUnits(Builder $query, User $user): Builder
    {
        if ($this->canBypassPropertyScope($user)) {
            return $query;
        }

        $ids = $this->assignedPropertyIds($user);

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        /* Resolve floor IDs once per request — avoids nested whereHas subquery on units */
        $floorIds = PropertyFloor::query()
            ->whereIn('property_id', $ids)
            ->pluck('id')
            ->all();

        return $floorIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('property_floor_id', $floorIds);
    }

    public function scopeContracts(Builder $query, User $user): Builder
    {
        if ($this->canBypassPropertyScope($user)) {
            return $query;
        }

        $ids = $this->assignedPropertyIds($user);

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        /* Resolve floor IDs once — avoids nested whereHas subquery on contracts */
        $floorIds = PropertyFloor::query()
            ->whereIn('property_id', $ids)
            ->pluck('id')
            ->all();

        if ($floorIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('unit', fn (Builder $innerQuery) => $innerQuery->whereIn('property_floor_id', $floorIds));
    }

    public function scopeCustomers(Builder $query, User $user): Builder
    {
        if ($this->canBypassPropertyScope($user)) {
            return $query;
        }

        $ids = $this->assignedPropertyIds($user);

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        /* Resolve floor IDs once — avoids triple-nested whereHas subquery */
        $floorIds = PropertyFloor::query()
            ->whereIn('property_id', $ids)
            ->pluck('id')
            ->all();

        if ($floorIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('contracts.unit', fn (Builder $innerQuery) => $innerQuery->whereIn('property_floor_id', $floorIds));
    }

    public function canAccessPropertyModel(User $user, Property $property): bool
    {
        return $this->userCanAccessProperty($user, (int) $property->id);
    }

    public function canAccessPropertyFloorModel(User $user, PropertyFloor $propertyFloor): bool
    {
        return $this->userCanAccessProperty($user, (int) $propertyFloor->property_id);
    }

    public function canAccessUnitModel(User $user, Unit $unit): bool
    {
        $propertyId = $unit->relationLoaded('propertyFloor')
            ? $unit->propertyFloor?->property_id
            : $unit->propertyFloor()->value('property_id');

        return $propertyId !== null && $this->userCanAccessProperty($user, (int) $propertyId);
    }

    public function canAccessCustomerContractModel(User $user, CustomerContract $customerContract): bool
    {
        $propertyId = $customerContract->relationLoaded('unit')
            ? $customerContract->unit?->propertyFloor?->property_id
            : Unit::query()
                ->join('property_floors', 'property_floors.id', '=', 'units.property_floor_id')
                ->where('units.id', $customerContract->unit_id)
                ->value('property_floors.property_id');

        return $propertyId !== null && $this->userCanAccessProperty($user, (int) $propertyId);
    }

    public function canAccessCustomerModel(User $user, Customer $customer): bool
    {
        if ($this->canBypassPropertyScope($user)) {
            return true;
        }

        $ids = $this->assignedPropertyIds($user);
        if ($ids === []) {
            return false;
        }

        return $customer->contracts()
            ->whereHas('unit.propertyFloor', fn (Builder $innerQuery) => $innerQuery->whereIn('property_id', $ids))
            ->exists();
    }
}
