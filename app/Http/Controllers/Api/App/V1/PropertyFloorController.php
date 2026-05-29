<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\PropertyFloorIndexRequest;
use App\Http\Requests\Api\App\V1\StorePropertyFloorRequest;
use App\Http\Requests\Api\App\V1\UpdatePropertyFloorRequest;
use App\Http\Resources\App\V1\PropertyFloorResource;
use App\Models\Tenant\PropertyFloor;
use App\Models\Tenant\Property;
use App\Models\Tenant\User as TenantUser;
use App\Services\V1\PropertyAssignmentAccessService;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;

class PropertyFloorController extends Controller
{
    use InteractsWithTenantModels;

    public function __construct(private PropertyAssignmentAccessService $propertyAssignmentAccessService)
    {
    }

    public function index(PropertyFloorIndexRequest $request)
    {
        $this->authorize('viewAny', PropertyFloor::class);

        $filters = $request->validated();
        $tenantUser = request()->user();
        $query = PropertyFloor::query()->with(['property'])->withCount('units');

        if ($tenantUser instanceof TenantUser) {
            $this->propertyAssignmentAccessService->scopePropertyFloors($query, $tenantUser, 'property_id');
        }

        if (!empty($filters['property_uuid'] ?? null)) {
            $property = $this->resolveModelByUuid(Property::class, $filters['property_uuid']);
            if (!$property) {
                return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
            }

            $query->where('property_id', $property->id);
        }

        if (!empty($filters['search'] ?? null)) {
            $this->applyPrefixSearch($query, $filters['search'], ['name']);
        }

        if (!empty($filters['name'] ?? null)) {
            $query->where('name', 'like', $filters['name'].'%');
        }

        if (isset($filters['floor_number'])) {
            $query->where('floor_number', $filters['floor_number']);
        }

        $this->applySort($query, $filters['sort'] ?? null, ['name', 'floor_number', 'created_at'], 'floor_number', 'asc');
        $floors = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(PropertyFloorResource::collection($floors), ApiMessages::listRetrieved('property floors'));
    }

    public function store(StorePropertyFloorRequest $request)
    {
        $this->authorize('create', PropertyFloor::class);

        $data = $request->validated();
        $property = $this->resolveModelByUuid(Property::class, $data['property_uuid']);
        if (!$property) {
            return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
        }

        $tenantUser = request()->user();
        if ($tenantUser instanceof TenantUser
            && !$this->propertyAssignmentAccessService->canAccessPropertyModel($tenantUser, $property)) {
            return ApiResponse::forbidden(['property' => ['You do not have access to the selected property.']]);
        }

        $exists = PropertyFloor::query()
            ->where('property_id', $property->id)
            ->where('name', trim($data['name']))
            ->exists();

        if ($exists) {
            return ApiResponse::error('Floor already exists', ['name' => ['Duplicate floor name for this property']], 422);
        }

        $floorNumberExists = PropertyFloor::query()
            ->where('property_id', $property->id)
            ->where('floor_number', $data['floor_number'])
            ->exists();

        if ($floorNumberExists) {
            return ApiResponse::error('Floor already exists', ['floor_number' => ['Duplicate floor number for this property']], 422);
        }

        $floor = DB::transaction(fn () => PropertyFloor::query()->create([
            'property_id' => $property->id,
            'name' => trim($data['name']),
            'floor_number' => $data['floor_number'],
        ]));

        return ApiResponse::resource(new PropertyFloorResource($floor->load(['property'])->loadCount('units')), ApiMessages::created('property floor'), 201);
    }

    public function show(PropertyFloor $propertyFloor)
    {
        $this->authorize('view', $propertyFloor);

        return ApiResponse::resource(new PropertyFloorResource($propertyFloor->load(['property'])->loadCount('units')), ApiMessages::detailsRetrieved('property floor'));
    }

    public function update(UpdatePropertyFloorRequest $request, PropertyFloor $propertyFloor)
    {
        $this->authorize('update', $propertyFloor);

        $data = $request->validated();
        $property = $propertyFloor->property;

        if (!empty($data['property_uuid'] ?? null)) {
            $property = $this->resolveModelByUuid(Property::class, $data['property_uuid']);
            if (!$property) {
                return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
            }
        }

        $tenantUser = request()->user();
        if ($tenantUser instanceof TenantUser
            && !$this->propertyAssignmentAccessService->canAccessPropertyModel($tenantUser, $property)) {
            return ApiResponse::forbidden(['property' => ['You do not have access to the selected property.']]);
        }

        $name = isset($data['name']) ? trim($data['name']) : $propertyFloor->name;
        $floorNumber = $data['floor_number'] ?? $propertyFloor->floor_number;
        $exists = PropertyFloor::query()
            ->where('property_id', $property->id)
            ->where('name', $name)
            ->whereKeyNot($propertyFloor->id)
            ->exists();

        if ($exists) {
            return ApiResponse::error('Floor already exists', ['name' => ['Duplicate floor name for this property']], 422);
        }

        $floorNumberExists = PropertyFloor::query()
            ->where('property_id', $property->id)
            ->where('floor_number', $floorNumber)
            ->whereKeyNot($propertyFloor->id)
            ->exists();

        if ($floorNumberExists) {
            return ApiResponse::error('Floor already exists', ['floor_number' => ['Duplicate floor number for this property']], 422);
        }

        DB::transaction(function () use ($propertyFloor, $property, $name, $floorNumber) {
            $propertyFloor->fill([
                'property_id' => $property->id,
                'name' => $name,
                'floor_number' => $floorNumber,
            ])->save();
        });

        return ApiResponse::resource(
            new PropertyFloorResource($propertyFloor->fresh()->load(['property'])->loadCount('units')),
            ApiMessages::updated('property floor')
        );
    }

    public function destroy(PropertyFloor $propertyFloor)
    {
        $this->authorize('delete', $propertyFloor);

        DB::transaction(fn () => $propertyFloor->delete());

        return ApiResponse::success(ApiMessages::deleted('property floor'));
    }
}
