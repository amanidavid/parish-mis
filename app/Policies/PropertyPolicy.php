<?php

namespace App\Policies;

use App\Models\Tenant\Property;
use App\Models\Tenant\User;

class PropertyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('properties.view');
    }

    public function view(User $user, Property $property): bool
    {
        return $user->hasPermissionTo('properties.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('properties.create');
    }

    public function update(User $user, Property $property): bool
    {
        return $user->hasPermissionTo('properties.update');
    }

    public function delete(User $user, Property $property): bool
    {
        return $user->hasPermissionTo('properties.delete');
    }
}
