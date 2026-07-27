<?php

namespace App\Policies;

use App\Models\Landlord\PropertyInvoice;
use App\Models\Tenant\Property;
use App\Models\Tenant\User;
use App\Services\V1\PropertyAssignmentAccessService;

class PropertyInvoicePolicy
{
    public function __construct(
        private PropertyAssignmentAccessService $propertyAssignmentAccessService,
    ) {
    }

    public function viewAny(User $user, Property $property): bool
    {
        return $user->hasPermissionTo('property_invoices.view')
            && $this->propertyAssignmentAccessService->canAccessPropertyModel($user, $property);
    }

    public function viewWorkspace(User $user): bool
    {
        return $user->hasPermissionTo('property_invoices.view');
    }

    public function view(User $user, PropertyInvoice $propertyInvoice, Property $property): bool
    {
        return $user->hasPermissionTo('property_invoices.view')
            && $this->propertyAssignmentAccessService->canAccessPropertyModel($user, $property)
            && $propertyInvoice->property_uuid === $property->uuid;
    }

    public function download(User $user, PropertyInvoice $propertyInvoice, Property $property): bool
    {
        return $user->hasPermissionTo('property_invoices.download')
            && $this->propertyAssignmentAccessService->canAccessPropertyModel($user, $property)
            && $propertyInvoice->property_uuid === $property->uuid;
    }
}
