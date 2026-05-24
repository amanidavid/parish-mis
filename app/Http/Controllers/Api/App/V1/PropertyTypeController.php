<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\PropertyTypeIndexRequest;
use App\Http\Requests\Api\App\V1\StorePropertyTypeRequest;
use App\Http\Requests\Api\App\V1\UpdatePropertyTypeRequest;
use App\Http\Resources\App\V1\PropertyTypeResource;
use App\Models\Tenant\PropertyType;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;

class PropertyTypeController extends Controller
{
    use InteractsWithTenantModels;

    public function index(PropertyTypeIndexRequest $request)
    {
        $this->authorize('viewAny', PropertyType::class);

        $filters = $request->validated();
        $query = PropertyType::query()->withCount('properties');

        if (!empty($filters['search'] ?? null)) {
            $this->applyPrefixSearch($query, $filters['search'], ['name']);
        }

        if (!empty($filters['name'] ?? null)) {
            $query->where('name', 'like', $filters['name'].'%');
        }

        $this->applySort($query, $filters['sort'] ?? null, ['name', 'created_at'], 'name', 'asc');
        $propertyTypes = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(PropertyTypeResource::collection($propertyTypes), 'Property types list');
    }

    public function store(StorePropertyTypeRequest $request)
    {
        $this->authorize('create', PropertyType::class);

        $data = $request->validated();
        $exists = PropertyType::query()->where('name', $data['name'])->exists();
        if ($exists) {
            return ApiResponse::error('Property type already exists', ['name' => ['Duplicate property type name']], 422);
        }

        $propertyType = DB::transaction(fn () => PropertyType::query()->create([
            'name' => trim($data['name']),
        ]));

        return ApiResponse::resource(new PropertyTypeResource($propertyType), 'Property type created', 201);
    }

    public function show(PropertyType $propertyType)
    {
        $this->authorize('view', $propertyType);

        return ApiResponse::resource(new PropertyTypeResource($propertyType->loadCount('properties')), 'Property type details');
    }

    public function update(UpdatePropertyTypeRequest $request, PropertyType $propertyType)
    {
        $this->authorize('update', $propertyType);

        $data = $request->validated();
        if (isset($data['name'])) {
            $exists = PropertyType::query()
                ->where('name', $data['name'])
                ->whereKeyNot($propertyType->id)
                ->exists();

            if ($exists) {
                return ApiResponse::error('Property type already exists', ['name' => ['Duplicate property type name']], 422);
            }
        }

        DB::transaction(function () use ($propertyType, $data) {
            $propertyType->fill([
                'name' => isset($data['name']) ? trim($data['name']) : $propertyType->name,
            ])->save();
        });

        return ApiResponse::resource(new PropertyTypeResource($propertyType->fresh()->loadCount('properties')), 'Property type updated');
    }

    public function destroy(PropertyType $propertyType)
    {
        $this->authorize('delete', $propertyType);

        DB::transaction(fn () => $propertyType->delete());

        return ApiResponse::success('Property type deleted');
    }
}
