<?php

namespace App\Policies;

use App\Models\Tenant\DailyExpenseType;
use App\Models\Tenant\User;

class DailyExpenseTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('daily_expense_types.view');
    }

    public function view(User $user, DailyExpenseType $dailyExpenseType): bool
    {
        return $user->hasPermissionTo('daily_expense_types.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('daily_expense_types.create');
    }

    public function update(User $user, DailyExpenseType $dailyExpenseType): bool
    {
        return $user->hasPermissionTo('daily_expense_types.update');
    }

    public function delete(User $user, DailyExpenseType $dailyExpenseType): bool
    {
        return $user->hasPermissionTo('daily_expense_types.delete');
    }
}
