<?php

namespace App\Policies;

use App\Models\Tenant\Customer;
use App\Models\Tenant\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->hasPermissionTo('customers.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('customers.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->hasPermissionTo('customers.update');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->hasPermissionTo('customers.delete');
    }
}
