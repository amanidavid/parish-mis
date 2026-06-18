<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\CountryIndexRequest;
use App\Http\Requests\Api\App\V1\DistrictIndexRequest;
use App\Http\Requests\Api\App\V1\RegionIndexRequest;
use App\Http\Requests\Api\App\V1\WardIndexRequest;
use App\Http\Resources\App\V1\CountryResource;
use App\Http\Resources\App\V1\DistrictResource;
use App\Http\Resources\App\V1\RegionResource;
use App\Http\Resources\App\V1\WardResource;
use App\Models\Tenant\Country;
use App\Models\Tenant\District;
use App\Models\Tenant\Region;
use App\Models\Tenant\Ward;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;

class LocationController extends Controller
{
    use InteractsWithTenantModels;

    /**
     * Handle countries.
     */
    public function countries(CountryIndexRequest $request)
    {
        $this->authorize('viewAny', Country::class);

        $filters = $request->validated();
        $query = Country::query()->withCount('regions');

        if (!empty($filters['search'] ?? null)) {
            $this->applyCountrySearch($query, $filters['search'], $filters['status'] ?? null);
        }

        if (!empty($filters['name'] ?? null)) {
            $query->where('name', 'like', $filters['name'].'%');
        }

        if (!empty($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        $countries = $query->orderBy('name')->paginate((int) ($filters['per_page'] ?? 300));

        return ApiResponse::resource(CountryResource::collection($countries), 'Countries list');
    }

    /**
     * Apply country search.
     */
    private function applyCountrySearch(Builder $query, string $search, ?string $status = null): void
    {
        $search = trim($search);

        if ($search === '') {
            return;
        }

        $normalizedDialCode = preg_replace('/\D+/', '', $search) ?? '';
        if ($normalizedDialCode !== '' && preg_match('/^[+0-9\s]+$/', $search) === 1) {
            $query->where('dial_code_search', 'like', $normalizedDialCode.'%');

            return;
        }

        $normalizedCode = strtoupper($search);
        if (preg_match('/^[a-zA-Z]{1,3}$/', $search) === 1) {
            $query->where(function (Builder $innerQuery) use ($normalizedCode, $search, $status) {
                $innerQuery->where('code', 'like', $normalizedCode.'%');

                if ($status !== null) {
                    $innerQuery->orWhere(function (Builder $nameQuery) use ($search, $status) {
                        $nameQuery
                            ->where('status', $status)
                            ->where('name', 'like', $search.'%');
                    });
                } else {
                    $innerQuery->orWhere('name', 'like', $search.'%');
                }
            });

            return;
        }

        $query->where('name', 'like', $search.'%');
    }

    /**
     * Handle regions.
     */
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

    /**
     * Handle districts.
     */
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

    /**
     * Handle wards.
     */
    public function wards(WardIndexRequest $request)
    {
        $this->authorize('viewAny', Country::class);

        $filters = $request->validated();
        $query = Ward::query()->with([
            'district:id,uuid,name,region_id',
            'district.region:id,uuid,country_id',
        ]);

        if (!empty($filters['district_uuid'] ?? null)) {
            $district = $this->resolveModelByUuid(District::class, $filters['district_uuid']);
            if (!$district) {
                return ApiResponse::error('District not found', ['district_uuid' => ['Invalid district identifier']], 422);
            }

            $query->where('district_id', $district->id);
        } elseif (!empty($filters['region_uuid'] ?? null)) {
            $region = $this->resolveModelByUuid(Region::class, $filters['region_uuid']);
            if (!$region) {
                return ApiResponse::error('Region not found', ['region_uuid' => ['Invalid region identifier']], 422);
            }

            $query->whereHas('district', fn ($innerQuery) => $innerQuery->where('region_id', $region->id));
        } elseif (!empty($filters['country_uuid'] ?? null)) {
            $query->whereHas('district.region.country', fn ($innerQuery) => $innerQuery->where('uuid', $filters['country_uuid']));
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

        $wards = $query
            ->orderBy('district_id')
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 100));

        return ApiResponse::resource(WardResource::collection($wards), 'Wards list');
    }
}
