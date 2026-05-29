<?php

namespace App\Policies;

use App\Models\Tenant\PropertyFloor;
use App\Models\Tenant\User;
use App\Services\V1\PropertyAssignmentAccessService;

class PropertyFloorPolicy
{
    public function __construct(private PropertyAssignmentAccessService $propertyAssignmentAccessService)
    {
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('property_floors.view');
    }

    public function view(User $user, PropertyFloor $propertyFloor): bool
    {
        return $user->hasPermissionTo('property_floors.view')
            && $this->propertyAssignmentAccessService->canAccessPropertyFloorModel($user, $propertyFloor);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('property_floors.create');
    }

    public function update(User $user, PropertyFloor $propertyFloor): bool
    {
        return $user->hasPermissionTo('property_floors.update')
            && $this->propertyAssignmentAccessService->canAccessPropertyFloorModel($user, $propertyFloor);
    }

    public function delete(User $user, PropertyFloor $propertyFloor): bool
    {
        return $user->hasPermissionTo('property_floors.delete')
            && $this->propertyAssignmentAccessService->canAccessPropertyFloorModel($user, $propertyFloor);
    }
}
