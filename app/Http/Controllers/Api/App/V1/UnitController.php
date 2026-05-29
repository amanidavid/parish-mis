<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\StoreUnitRequest;
use App\Http\Requests\Api\App\V1\UnitIndexRequest;
use App\Http\Requests\Api\App\V1\UpdateUnitRequest;
use App\Http\Resources\App\V1\UnitResource;
use App\Models\Tenant\Property;
use App\Models\Tenant\PropertyFloor;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User as TenantUser;
use App\Services\V1\PropertyAssignmentAccessService;
use App\Services\V1\SubscriptionService;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;

class UnitController extends Controller
{
    use InteractsWithTenantModels;

    public function __construct(
        private SubscriptionService $subscriptionService,
        private PropertyAssignmentAccessService $propertyAssignmentAccessService,
    )
    {
    }

    public function index(UnitIndexRequest $request)
    {
        $this->authorize('viewAny', Unit::class);

        $filters = $request->validated();
        $tenantUser = request()->user();
        $query = Unit::query()->with('propertyFloor.property');

        if ($tenantUser instanceof TenantUser) {
            $this->propertyAssignmentAccessService->scopeUnits($query, $tenantUser);
        }

        if (!empty($filters['property_uuid'] ?? null)) {
            $property = $this->resolveModelByUuid(Property::class, $filters['property_uuid']);
            if (!$property) {
                return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
            }

            $query->whereHas('propertyFloor', fn ($innerQuery) => $innerQuery->where('property_id', $property->id));
        }

        if (!empty($filters['property_floor_uuid'] ?? null)) {
            $propertyFloor = $this->resolveModelByUuid(PropertyFloor::class, $filters['property_floor_uuid']);
            if (!$propertyFloor) {
                return ApiResponse::error('Property floor not found', ['property_floor_uuid' => ['Invalid floor identifier']], 422);
            }

            $query->where('property_floor_id', $propertyFloor->id);
        }

        if (!empty($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'] ?? null)) {
            $this->applyPrefixSearch($query, $filters['search'], ['unit_number']);
        }

        if (!empty($filters['unit_number'] ?? null)) {
            $query->where('unit_number', 'like', $filters['unit_number'].'%');
        }

        $this->applySort($query, $filters['sort'] ?? null, ['unit_number', 'created_at'], 'unit_number', 'asc');
        $units = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(UnitResource::collection($units), ApiMessages::listRetrieved('units'));
    }

    public function store(StoreUnitRequest $request)
    {
        $this->authorize('create', Unit::class);
        $this->assertWorkspaceAllowsInventoryMutation();

        $data = $request->validated();
        $propertyFloor = $this->resolveModelByUuid(PropertyFloor::class, $data['property_floor_uuid']);
        if (!$propertyFloor) {
            return ApiResponse::error('Property floor not found', ['property_floor_uuid' => ['Invalid floor identifier']], 422);
        }

        $tenantUser = request()->user();
        if ($tenantUser instanceof TenantUser
            && !$this->propertyAssignmentAccessService->userCanAccessProperty($tenantUser, (int) $propertyFloor->property_id)) {
            return ApiResponse::forbidden(['property_floor' => ['You do not have access to the selected property floor.']]);
        }

        $exists = Unit::query()
            ->where('property_floor_id', $propertyFloor->id)
            ->where('unit_number', $data['unit_number'])
            ->exists();

        if ($exists) {
            return ApiResponse::error('Unit already exists', ['unit_number' => ['Duplicate unit number for this floor']], 422);
        }

        $unit = DB::transaction(fn () => Unit::query()->create([
            'property_floor_id' => $propertyFloor->id,
            'unit_number' => trim($data['unit_number']),
            'status' => $data['status'] ?? 'vacant',
        ]));

        $this->syncWorkspaceUsage();

        return ApiResponse::resource(new UnitResource($unit->load('propertyFloor.property')), ApiMessages::created('unit'), 201);
    }

    public function show(Unit $unit)
    {
        $this->authorize('view', $unit);

        return ApiResponse::resource(new UnitResource($unit->load('propertyFloor.property')), ApiMessages::detailsRetrieved('unit'));
    }

    public function update(UpdateUnitRequest $request, Unit $unit)
    {
        $this->authorize('update', $unit);
        $this->assertWorkspaceAllowsInventoryMutation();

        $data = $request->validated();
        $propertyFloor = $unit->propertyFloor;

        if (!empty($data['property_floor_uuid'] ?? null)) {
            $propertyFloor = $this->resolveModelByUuid(PropertyFloor::class, $data['property_floor_uuid']);
            if (!$propertyFloor) {
                return ApiResponse::error('Property floor not found', ['property_floor_uuid' => ['Invalid floor identifier']], 422);
            }
        }

        $tenantUser = request()->user();
        if ($tenantUser instanceof TenantUser
            && !$this->propertyAssignmentAccessService->userCanAccessProperty($tenantUser, (int) $propertyFloor->property_id)) {
            return ApiResponse::forbidden(['property_floor' => ['You do not have access to the selected property floor.']]);
        }

        $unitNumber = isset($data['unit_number']) ? trim($data['unit_number']) : $unit->unit_number;
        $exists = Unit::query()
            ->where('property_floor_id', $propertyFloor->id)
            ->where('unit_number', $unitNumber)
            ->whereKeyNot($unit->id)
            ->exists();

        if ($exists) {
            return ApiResponse::error('Unit already exists', ['unit_number' => ['Duplicate unit number for this floor']], 422);
        }

        DB::transaction(function () use ($unit, $propertyFloor, $unitNumber, $data) {
            $unit->fill([
                'property_floor_id' => $propertyFloor->id,
                'unit_number' => $unitNumber,
                'status' => $data['status'] ?? $unit->status,
            ])->save();
        });

        return ApiResponse::resource(new UnitResource($unit->fresh()->load('propertyFloor.property')), ApiMessages::updated('unit'));
    }

    public function destroy(Unit $unit)
    {
        $this->authorize('delete', $unit);
        $this->assertWorkspaceAllowsInventoryMutation();

        DB::transaction(fn () => $unit->delete());

        $this->syncWorkspaceUsage();

        return ApiResponse::success(ApiMessages::deleted('unit'));
    }

    private function syncWorkspaceUsage(): void
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof \App\Models\Tenancy\Tenant) {
            $this->subscriptionService->syncWorkspaceUsage($tenant);
        }
    }

    private function assertWorkspaceAllowsInventoryMutation(): void
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof \App\Models\Tenancy\Tenant) {
            $this->subscriptionService->assertWorkspaceAllowsInventoryMutation($tenant);
        }
    }
}
