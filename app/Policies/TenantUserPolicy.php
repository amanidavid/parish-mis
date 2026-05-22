<?php

namespace App\Policies;

use App\Models\Tenant\User;

class TenantUserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('staff.manage');
    }

    public function view(User $user, User $tenantUser): bool
    {
        return $user->hasPermissionTo('staff.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('staff.manage');
    }

    public function update(User $user, User $tenantUser): bool
    {
        return $user->hasPermissionTo('staff.manage');
    }

    public function delete(User $user, User $tenantUser): bool
    {
        return $user->hasPermissionTo('staff.manage');
    }
}
