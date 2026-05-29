<?php

namespace App\Policies;

use App\Models\Tenant\CustomerContract;
use App\Models\Tenant\User;
use App\Services\V1\PropertyAssignmentAccessService;

class CustomerContractPolicy
{
    public function __construct(private PropertyAssignmentAccessService $propertyAssignmentAccessService)
    {
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('customer_contracts.view');
    }

    public function view(User $user, CustomerContract $customerContract): bool
    {
        return $user->hasPermissionTo('customer_contracts.view')
            && $this->propertyAssignmentAccessService->canAccessCustomerContractModel($user, $customerContract);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('customer_contracts.create');
    }

    public function update(User $user, CustomerContract $customerContract): bool
    {
        return $user->hasPermissionTo('customer_contracts.update')
            && $this->propertyAssignmentAccessService->canAccessCustomerContractModel($user, $customerContract);
    }

    public function delete(User $user, CustomerContract $customerContract): bool
    {
        return $user->hasPermissionTo('customer_contracts.delete')
            && $this->propertyAssignmentAccessService->canAccessCustomerContractModel($user, $customerContract);
    }
}
