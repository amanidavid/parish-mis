<?php

namespace App\Policies;

use App\Models\Tenant\DailyPropertyExpense;
use App\Models\Tenant\User;
use App\Services\V1\PropertyAssignmentAccessService;

class DailyPropertyExpensePolicy
{
    public function __construct(private PropertyAssignmentAccessService $propertyAssignmentAccessService)
    {
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('daily_property_expenses.view');
    }

    public function view(User $user, DailyPropertyExpense $dailyPropertyExpense): bool
    {
        return $user->hasPermissionTo('daily_property_expenses.view')
            && $this->propertyAssignmentAccessService->canAccessDailyPropertyExpenseModel($user, $dailyPropertyExpense);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('daily_property_expenses.create');
    }

    public function update(User $user, DailyPropertyExpense $dailyPropertyExpense): bool
    {
        return $user->hasPermissionTo('daily_property_expenses.update')
            && $this->propertyAssignmentAccessService->canAccessDailyPropertyExpenseModel($user, $dailyPropertyExpense);
    }

    public function delete(User $user, DailyPropertyExpense $dailyPropertyExpense): bool
    {
        return $user->hasPermissionTo('daily_property_expenses.delete')
            && $this->propertyAssignmentAccessService->canAccessDailyPropertyExpenseModel($user, $dailyPropertyExpense);
    }
}
