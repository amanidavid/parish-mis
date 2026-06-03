<?php

namespace App\Policies;

use App\Models\Tenant\MaintenanceJob;
use App\Models\Tenant\User;
use App\Services\V1\PropertyAssignmentAccessService;

class MaintenanceJobPolicy
{
    public function __construct(private PropertyAssignmentAccessService $propertyAssignmentAccessService)
    {
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('maintenance_jobs.view');
    }

    public function view(User $user, MaintenanceJob $maintenanceJob): bool
    {
        return $user->hasPermissionTo('maintenance_jobs.view')
            && $this->propertyAssignmentAccessService->canAccessMaintenanceJobModel($user, $maintenanceJob);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('maintenance_jobs.create');
    }

    public function update(User $user, MaintenanceJob $maintenanceJob): bool
    {
        return $user->hasPermissionTo('maintenance_jobs.update')
            && $this->propertyAssignmentAccessService->canAccessMaintenanceJobModel($user, $maintenanceJob);
    }

    public function delete(User $user, MaintenanceJob $maintenanceJob): bool
    {
        return $user->hasPermissionTo('maintenance_jobs.delete')
            && $this->propertyAssignmentAccessService->canAccessMaintenanceJobModel($user, $maintenanceJob);
    }
}
