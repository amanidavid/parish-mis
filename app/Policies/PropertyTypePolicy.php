<?php

namespace App\Policies;

use App\Models\Tenant\PropertyType;
use App\Models\Tenant\User;

class PropertyTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('property_types.view');
    }

    public function view(User $user, PropertyType $propertyType): bool
    {
        return $user->hasPermissionTo('property_types.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('property_types.create');
    }

    public function update(User $user, PropertyType $propertyType): bool
    {
        return $user->hasPermissionTo('property_types.update');
    }

    public function delete(User $user, PropertyType $propertyType): bool
    {
        return $user->hasPermissionTo('property_types.delete');
    }
}
