<?php

namespace App\Services\V1\Maintenance;

use App\Models\Tenant\MaintenanceJob;
use App\Models\Tenant\Property;
use App\Models\Tenant\PropertyFloor;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User;
use App\Services\V1\PropertyAssignmentAccessService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class MaintenanceHierarchyService
{
    public function __construct(private PropertyAssignmentAccessService $propertyAssignmentAccessService)
    {
    }

    public function resolveJobHierarchy(array $payload): array|JsonResponse
    {
        $property = Property::query()
            ->select(['id', 'uuid', 'name'])
            ->where('uuid', $payload['property_uuid'])
            ->first();

        if (!$property) {
            return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
        }

        $propertyFloor = null;
        if (!empty($payload['property_floor_uuid'] ?? null)) {
            $propertyFloor = PropertyFloor::query()
                ->select(['id', 'uuid', 'property_id', 'name', 'floor_number'])
                ->where('uuid', $payload['property_floor_uuid'])
                ->first();

            if (!$propertyFloor) {
                return ApiResponse::error('Property floor not found', ['property_floor_uuid' => ['Invalid floor identifier']], 422);
            }

            if ((int) $propertyFloor->property_id !== (int) $property->id) {
                return ApiResponse::error(
                    'Property floor does not belong to the selected property',
                    ['property_floor_uuid' => ['The selected floor does not belong to the selected property.']],
                    422
                );
            }
        }

        $unit = null;
        if (!empty($payload['unit_uuid'] ?? null)) {
            $unit = Unit::query()
                ->select(['id', 'uuid', 'property_floor_id', 'unit_number', 'status'])
                ->with('propertyFloor:id,uuid,property_id,name,floor_number')
                ->where('uuid', $payload['unit_uuid'])
                ->first();

            if (!$unit) {
                return ApiResponse::error('Unit not found', ['unit_uuid' => ['Invalid unit identifier']], 422);
            }

            $unitPropertyFloor = $unit->propertyFloor;
            if (!$unitPropertyFloor || (int) $unitPropertyFloor->property_id !== (int) $property->id) {
                return ApiResponse::error(
                    'Unit does not belong to the selected property',
                    ['unit_uuid' => ['The selected unit does not belong to the selected property.']],
                    422
                );
            }

            if ($propertyFloor && (int) $unit->property_floor_id !== (int) $propertyFloor->id) {
                return ApiResponse::error(
                    'Unit does not belong to the selected floor',
                    ['unit_uuid' => ['The selected unit does not belong to the selected floor.']],
                    422
                );
            }

            $propertyFloor ??= $unitPropertyFloor;
        }

        return [
            'property' => $property,
            'property_floor' => $propertyFloor,
            'unit' => $unit,
        ];
    }

    public function resolveJobHierarchyForUpdate(MaintenanceJob $maintenanceJob, array $payload): array|JsonResponse
    {
        $propertyChanged = array_key_exists('property_uuid', $payload);
        $floorChanged = array_key_exists('property_floor_uuid', $payload);

        return $this->resolveJobHierarchy([
            'property_uuid' => $payload['property_uuid'] ?? $maintenanceJob->property?->uuid,
            'property_floor_uuid' => $floorChanged
                ? $payload['property_floor_uuid']
                : ($propertyChanged ? null : $maintenanceJob->propertyFloor?->uuid),
            'unit_uuid' => array_key_exists('unit_uuid', $payload)
                ? $payload['unit_uuid']
                : (($propertyChanged || $floorChanged) ? null : $maintenanceJob->unit?->uuid),
        ]);
    }

    public function ensurePropertyAccess(User $tenantUser, Property $property): ?JsonResponse
    {
        if ($this->propertyAssignmentAccessService->userCanAccessProperty($tenantUser, (int) $property->id)) {
            return null;
        }

        return ApiResponse::forbidden(['property' => ['You do not have access to the selected property.']]);
    }
}
