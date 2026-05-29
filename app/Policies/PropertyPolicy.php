<?php

namespace App\Policies;

use App\Models\Tenant\Property;
use App\Models\Tenant\User;
use App\Services\V1\PropertyAssignmentAccessService;

class PropertyPolicy
{
    public function __construct(private PropertyAssignmentAccessService $propertyAssignmentAccessService)
    {
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('properties.view');
    }

    public function view(User $user, Property $property): bool
    {
        return $user->hasPermissionTo('properties.view')
            && $this->propertyAssignmentAccessService->canAccessPropertyModel($user, $property);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('properties.create')
            && $this->propertyAssignmentAccessService->canBypassPropertyScope($user);
    }

    public function update(User $user, Property $property): bool
    {
        return $user->hasPermissionTo('properties.update')
            && $this->propertyAssignmentAccessService->canAccessPropertyModel($user, $property);
    }

    public function delete(User $user, Property $property): bool
    {
        return $user->hasPermissionTo('properties.delete')
            && $this->propertyAssignmentAccessService->canAccessPropertyModel($user, $property);
    }
}
