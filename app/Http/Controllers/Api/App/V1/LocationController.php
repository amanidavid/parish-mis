<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\CountryIndexRequest;
use App\Http\Requests\Api\App\V1\DistrictIndexRequest;
use App\Http\Requests\Api\App\V1\RegionIndexRequest;
use App\Http\Resources\App\V1\CountryResource;
use App\Http\Resources\App\V1\DistrictResource;
use App\Http\Resources\App\V1\RegionResource;
use App\Models\Tenant\Country;
use App\Models\Tenant\District;
use App\Models\Tenant\Region;
use App\Support\ApiResponse;

class LocationController extends Controller
{
    use InteractsWithTenantModels;

    public function countries(CountryIndexRequest $request)
    {
        $this->authorize('viewAny', Country::class);

        $filters = $request->validated();
        $query = Country::query()->withCount('regions');

        if (!empty($filters['search'] ?? null)) {
            $this->applyPrefixSearch($query, $filters['search'], ['name', 'code', 'dial_code']);
        }

        if (!empty($filters['name'] ?? null)) {
            $query->where('name', 'like', $filters['name'].'%');
        }

        if (!empty($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        $countries = $query->orderBy('name')->paginate((int) ($filters['per_page'] ?? 100));

        return ApiResponse::resource(CountryResource::collection($countries), 'Countries list');
    }

    public function regions(RegionIndexRequest $request)
    {
        $this->authorize('viewAny', Country::class);

        $filters = $request->validated();
        $query = Region::query()
            ->with(['country:id,uuid,name,code'])
            ->withCount('districts');

        if (!empty($filters['country_uuid'] ?? null)) {
            $country = $this->resolveModelByUuid(Country::class, $filters['country_uuid']);
            if (!$country) {
                return ApiResponse::error('Country not found', ['country_uuid' => ['Invalid country identifier']], 422);
            }

            $query->where('country_id', $country->id);
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

        $regions = $query->orderBy('name')->paginate((int) ($filters['per_page'] ?? 100));

        return ApiResponse::resource(RegionResource::collection($regions), 'Regions list');
    }

    public function districts(DistrictIndexRequest $request)
    {
        $this->authorize('viewAny', Country::class);

        $filters = $request->validated();
        $query = District::query()
            ->with([
                'region:id,uuid,name,country_id',
                'region.country:id,uuid',
            ]);

        if (!empty($filters['country_uuid'] ?? null)) {
            $query->whereHas('region.country', fn ($innerQuery) => $innerQuery->where('uuid', $filters['country_uuid']));
        }

        if (!empty($filters['region_uuid'] ?? null)) {
            $region = $this->resolveModelByUuid(Region::class, $filters['region_uuid']);
            if (!$region) {
                return ApiResponse::error('Region not found', ['region_uuid' => ['Invalid region identifier']], 422);
            }

            $query->where('region_id', $region->id);
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

        $districts = $query->orderBy('name')->paginate((int) ($filters['per_page'] ?? 100));

        return ApiResponse::resource(DistrictResource::collection($districts), 'Districts list');
    }
}
