<?php

namespace App\Policies;

use App\Models\Tenant\StaffPropertyAssignment;
use App\Models\Tenant\User;

class StaffPropertyAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('staff_property_assignments.view');
    }

    public function view(User $user, StaffPropertyAssignment $staffPropertyAssignment): bool
    {
        return $user->hasPermissionTo('staff_property_assignments.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('staff_property_assignments.create');
    }

    public function update(User $user, StaffPropertyAssignment $staffPropertyAssignment): bool
    {
        return $user->hasPermissionTo('staff_property_assignments.update');
    }

    public function delete(User $user, StaffPropertyAssignment $staffPropertyAssignment): bool
    {
        return $user->hasPermissionTo('staff_property_assignments.delete');
    }
}
