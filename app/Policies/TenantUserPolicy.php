<?php

namespace App\Policies;

use App\Models\Tenant\User;

class TenantUserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasStaffPermission($user, 'staff.view');
    }

    public function view(User $user, User $tenantUser): bool
    {
        return $this->hasStaffPermission($user, 'staff.view');
    }

    public function create(User $user): bool
    {
        return $this->hasStaffPermission($user, 'staff.create');
    }

    public function update(User $user, User $tenantUser): bool
    {
        return $this->hasStaffPermission($user, 'staff.update');
    }

    public function delete(User $user, User $tenantUser): bool
    {
        return $this->hasStaffPermission($user, 'staff.delete');
    }

    private function hasStaffPermission(User $user, string $permission): bool
    {
        return $user->hasPermissionTo('staff.manage')
            || $user->hasPermissionTo($permission);
    }
}
