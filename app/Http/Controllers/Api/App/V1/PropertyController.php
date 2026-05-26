<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\PropertyIndexRequest;
use App\Http\Requests\Api\App\V1\StorePropertyRequest;
use App\Http\Requests\Api\App\V1\UpdatePropertyRequest;
use App\Http\Resources\App\V1\PropertyResource;
use App\Models\Tenant\District;
use App\Models\Tenant\Property;
use App\Models\Tenant\PropertyType;
use App\Models\Tenant\Region;
use App\Services\V1\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;

class PropertyController extends Controller
{
    use InteractsWithTenantModels;

    public function __construct(private SubscriptionService $subscriptionService)
    {
    }

    public function index(PropertyIndexRequest $request)
    {
        $this->authorize('viewAny', Property::class);

        $filters = $request->validated();
        $query = Property::query()->with(['type', 'district.region.country'])->withCount(['floors', 'units']);

        if (!empty($filters['type_uuid'] ?? null)) {
            $type = $this->resolveModelByUuid(PropertyType::class, $filters['type_uuid']);
            if (!$type) {
                return ApiResponse::error('Property type not found', ['type_uuid' => ['Invalid property type identifier']], 422);
            }

            $query->where('type_id', $type->id);
        }

        if (!empty($filters['country_uuid'] ?? null)) {
            $query->whereHas('district.region.country', fn ($innerQuery) => $innerQuery->where('uuid', $filters['country_uuid']));
        }

        if (!empty($filters['region_uuid'] ?? null)) {
            $region = $this->resolveModelByUuid(Region::class, $filters['region_uuid']);
            if (!$region) {
                return ApiResponse::error('Region not found', ['region_uuid' => ['Invalid region identifier']], 422);
            }

            $query->whereHas('district.region', fn ($innerQuery) => $innerQuery->whereKey($region->id));
        }

        if (!empty($filters['district_uuid'] ?? null)) {
            $district = $this->resolveModelByUuid(District::class, $filters['district_uuid']);
            if (!$district) {
                return ApiResponse::error('District not found', ['district_uuid' => ['Invalid district identifier']], 422);
            }

            $query->where('district_id', $district->id);
        }

        if (!empty($filters['search'] ?? null)) {
            $this->applyPrefixSearch($query, $filters['search'], ['name']);
        }

        if (!empty($filters['name'] ?? null)) {
            $query->where('name', 'like', $filters['name'].'%');
        }

        if (!empty($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        $this->applySort($query, $filters['sort'] ?? null, ['name', 'created_at'], 'name', 'asc');
        $properties = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(PropertyResource::collection($properties), 'Properties list');
    }

    public function store(StorePropertyRequest $request)
    {
        $this->authorize('create', Property::class);
        $this->assertWorkspaceAllowsInventoryMutation();

        $data = $request->validated();
        $type = null;
        $district = null;
        if (!empty($data['type_uuid'] ?? null)) {
            $type = $this->resolveModelByUuid(PropertyType::class, $data['type_uuid']);
            if (!$type) {
                return ApiResponse::error('Property type not found', ['type_uuid' => ['Invalid property type identifier']], 422);
            }
        }

        if (!empty($data['district_uuid'] ?? null)) {
            $district = $this->resolveModelByUuid(District::class, $data['district_uuid']);
            if (!$district) {
                return ApiResponse::error('District not found', ['district_uuid' => ['Invalid district identifier']], 422);
            }
        }

        $exists = Property::query()->where('name', $data['name'])->exists();

        if ($exists) {
            return ApiResponse::error('A property with this name already exists', ['name' => ['Duplicate property name']], 422);
        }

        $property = DB::transaction(fn () => Property::query()->create([
            'name' => trim($data['name']),
            'type_id' => $type?->id,
            'district_id' => $district?->id,
            'address_line' => $data['address_line'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]));

        $this->syncWorkspaceUsage();

        return ApiResponse::resource(new PropertyResource($property->load(['type', 'district.region.country'])->loadCount(['floors', 'units'])), 'Property created', 201);
    }

    public function show(Property $property)
    {
        $this->authorize('view', $property);

        return ApiResponse::resource(
            new PropertyResource($property->load(['type', 'district.region.country'])->loadCount(['floors', 'units'])),
            'Property details'
        );
    }

    public function update(UpdatePropertyRequest $request, Property $property)
    {
        $this->authorize('update', $property);
        $this->assertWorkspaceAllowsInventoryMutation();

        $data = $request->validated();
        $typeId = $property->type_id;
        $districtId = $property->district_id;

        if (array_key_exists('type_uuid', $data)) {
            if ($data['type_uuid'] === null) {
                $typeId = null;
            } else {
                $type = $this->resolveModelByUuid(PropertyType::class, $data['type_uuid']);
                if (!$type) {
                    return ApiResponse::error('Property type not found', ['type_uuid' => ['Invalid property type identifier']], 422);
                }

                $typeId = $type->id;
            }
        }

        if (array_key_exists('district_uuid', $data)) {
            if ($data['district_uuid'] === null) {
                $districtId = null;
            } else {
                $district = $this->resolveModelByUuid(District::class, $data['district_uuid']);
                if (!$district) {
                    return ApiResponse::error('District not found', ['district_uuid' => ['Invalid district identifier']], 422);
                }

                $districtId = $district->id;
            }
        }

        $name = isset($data['name']) ? trim($data['name']) : $property->name;
        $exists = Property::query()
            ->where('name', $name)
            ->whereKeyNot($property->id)
            ->exists();

        if ($exists) {
            return ApiResponse::error('A property with this name already exists', ['name' => ['Duplicate property name']], 422);
        }

        DB::transaction(function () use ($property, $name, $typeId, $districtId, $data) {
            $property->fill([
                'name' => $name,
                'type_id' => $typeId,
                'district_id' => $districtId,
                'address_line' => array_key_exists('address_line', $data) ? $data['address_line'] : $property->address_line,
                'postal_code' => array_key_exists('postal_code', $data) ? $data['postal_code'] : $property->postal_code,
                'status' => $data['status'] ?? $property->status,
            ])->save();
        });

        return ApiResponse::resource(
            new PropertyResource($property->fresh()->load(['type', 'district.region.country'])->loadCount(['floors', 'units'])),
            'Property updated'
        );
    }

    public function destroy(Property $property)
    {
        $this->authorize('delete', $property);
        $this->assertWorkspaceAllowsInventoryMutation();

        DB::transaction(fn () => $property->delete());

        $this->syncWorkspaceUsage();

        return ApiResponse::success('Property deleted');
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
