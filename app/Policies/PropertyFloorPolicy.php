<?php

namespace App\Policies;

use App\Models\Tenant\PropertyFloor;
use App\Models\Tenant\User;

class PropertyFloorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('property_floors.view');
    }

    public function view(User $user, PropertyFloor $propertyFloor): bool
    {
        return $user->hasPermissionTo('property_floors.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('property_floors.create');
    }

    public function update(User $user, PropertyFloor $propertyFloor): bool
    {
        return $user->hasPermissionTo('property_floors.update');
    }

    public function delete(User $user, PropertyFloor $propertyFloor): bool
    {
        return $user->hasPermissionTo('property_floors.delete');
    }
}
