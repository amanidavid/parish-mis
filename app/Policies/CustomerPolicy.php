<?php

namespace App\Policies;

use App\Models\Tenant\Customer;
use App\Models\Tenant\User;
use App\Services\V1\PropertyAssignmentAccessService;

class CustomerPolicy
{
    public function __construct(private PropertyAssignmentAccessService $propertyAssignmentAccessService)
    {
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->hasPermissionTo('customers.view')
            && $this->propertyAssignmentAccessService->canAccessCustomerModel($user, $customer);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('customers.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->hasPermissionTo('customers.update')
            && $this->propertyAssignmentAccessService->canAccessCustomerModel($user, $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->hasPermissionTo('customers.delete')
            && $this->propertyAssignmentAccessService->canAccessCustomerModel($user, $customer);
    }
}
