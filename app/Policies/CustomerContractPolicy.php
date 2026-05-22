<?php

namespace App\Policies;

use App\Models\Tenant\CustomerContract;
use App\Models\Tenant\User;

class CustomerContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('customer_contracts.view');
    }

    public function view(User $user, CustomerContract $customerContract): bool
    {
        return $user->hasPermissionTo('customer_contracts.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('customer_contracts.create');
    }

    public function update(User $user, CustomerContract $customerContract): bool
    {
        return $user->hasPermissionTo('customer_contracts.update');
    }

    public function delete(User $user, CustomerContract $customerContract): bool
    {
        return $user->hasPermissionTo('customer_contracts.delete');
    }
}
