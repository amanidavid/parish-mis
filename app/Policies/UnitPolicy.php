<?php

namespace App\Policies;

use App\Models\Tenant\Unit;
use App\Models\Tenant\User;
use App\Services\V1\PropertyAssignmentAccessService;

class UnitPolicy
{
    public function __construct(private PropertyAssignmentAccessService $propertyAssignmentAccessService)
    {
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('units.view');
    }

    public function view(User $user, Unit $unit): bool
    {
        return $user->hasPermissionTo('units.view')
            && $this->propertyAssignmentAccessService->canAccessUnitModel($user, $unit);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('units.create');
    }

    public function update(User $user, Unit $unit): bool
    {
        return $user->hasPermissionTo('units.update')
            && $this->propertyAssignmentAccessService->canAccessUnitModel($user, $unit);
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $user->hasPermissionTo('units.delete')
            && $this->propertyAssignmentAccessService->canAccessUnitModel($user, $unit);
    }
}
