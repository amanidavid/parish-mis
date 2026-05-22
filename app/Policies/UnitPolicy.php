<?php

namespace App\Policies;

use App\Models\Tenant\Unit;
use App\Models\Tenant\User;

class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('units.view');
    }

    public function view(User $user, Unit $unit): bool
    {
        return $user->hasPermissionTo('units.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('units.create');
    }

    public function update(User $user, Unit $unit): bool
    {
        return $user->hasPermissionTo('units.update');
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $user->hasPermissionTo('units.delete');
    }
}
