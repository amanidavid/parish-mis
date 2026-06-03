<?php

namespace App\Policies;

use App\Models\Tenant\MaintenanceExpense;
use App\Models\Tenant\User;
use App\Services\V1\PropertyAssignmentAccessService;

class MaintenanceExpensePolicy
{
    public function __construct(private PropertyAssignmentAccessService $propertyAssignmentAccessService)
    {
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('maintenance_expenses.view');
    }

    public function view(User $user, MaintenanceExpense $maintenanceExpense): bool
    {
        return $user->hasPermissionTo('maintenance_expenses.view')
            && $this->propertyAssignmentAccessService->canAccessMaintenanceExpenseModel($user, $maintenanceExpense);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('maintenance_expenses.create');
    }

    public function update(User $user, MaintenanceExpense $maintenanceExpense): bool
    {
        return $user->hasPermissionTo('maintenance_expenses.update')
            && $this->propertyAssignmentAccessService->canAccessMaintenanceExpenseModel($user, $maintenanceExpense);
    }

    public function delete(User $user, MaintenanceExpense $maintenanceExpense): bool
    {
        return $user->hasPermissionTo('maintenance_expenses.delete')
            && $this->propertyAssignmentAccessService->canAccessMaintenanceExpenseModel($user, $maintenanceExpense);
    }
}
