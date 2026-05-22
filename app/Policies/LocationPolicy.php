<?php

namespace App\Policies;

use App\Models\Tenant\User;

class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('locations.view');
    }
}
