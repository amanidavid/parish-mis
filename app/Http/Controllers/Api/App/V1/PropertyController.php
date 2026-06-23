<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\PropertyIndexRequest;
use App\Http\Requests\Api\App\V1\StorePropertyRequest;
use App\Http\Requests\Api\App\V1\UpdatePropertyRequest;
use App\Http\Resources\App\V1\PropertyResource;
use App\Models\Tenant\Country;
use App\Models\Tenant\District;
use App\Models\Tenant\Property;
use App\Models\Tenant\PropertyType;
use App\Models\Tenant\Region;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenancy\Tenant;
use App\Models\Tenant\Ward;
use App\Services\V1\Billing\WorkspacePropertyRegistryService;
use App\Services\V1\Billing\PropertySubscriptionAccessService;
use App\Services\V1\PropertyMetricsService;
use App\Services\V1\Billing\SubscriptionUsageAdjustmentService;
use App\Services\V1\PropertyAssignmentAccessService;
use App\Services\V1\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PropertyController extends Controller
{
    use InteractsWithTenantModels;

    /**
     * Create a new instance.
     */
    public function __construct(
        private SubscriptionService $subscriptionService,
        private SubscriptionUsageAdjustmentService $subscriptionUsageAdjustmentService,
        private WorkspacePropertyRegistryService $workspacePropertyRegistryService,
        private PropertyMetricsService $propertyMetricsService,
        private PropertyAssignmentAccessService $propertyAssignmentAccessService,
        private PropertySubscriptionAccessService $propertySubscriptionAccessService,
    )
    {
    }

    /**
     * Handle the index request.
     */
    public function index(PropertyIndexRequest $request)
    {
        $this->authorize('viewAny', Property::class);

        $filters = $request->validated();
        $tenantUser = request()->user();
        $query = Property::query()
            ->with(['type', 'country', 'region.country', 'district.region.country', 'ward'])
            ->withCount(['floors', 'units']);

        if ($tenantUser instanceof TenantUser) {
            $this->propertyAssignmentAccessService->scopeProperties($query, $tenantUser, 'properties.id');
        }

        if (!empty($filters['type_uuid'] ?? null)) {
            $type = $this->resolveModelByUuid(PropertyType::class, $filters['type_uuid']);
            if (!$type) {
                return ApiResponse::error('Property type not found', ['type_uuid' => ['Invalid property type identifier']], 422);
            }

            $query->where('type_id', $type->id);
        }

        if (!empty($filters['country_uuid'] ?? null)) {
            $country = $this->resolveModelByUuid(Country::class, $filters['country_uuid']);
            if (!$country) {
                return ApiResponse::error('Country not found', ['country_uuid' => ['Invalid country identifier']], 422);
            }

            $query->where('country_id', $country->id);
        }

        if (!empty($filters['region_uuid'] ?? null)) {
            $region = $this->resolveModelByUuid(Region::class, $filters['region_uuid']);
            if (!$region) {
                return ApiResponse::error('Region not found', ['region_uuid' => ['Invalid region identifier']], 422);
            }

            $query->where('region_id', $region->id);
        }

        if (!empty($filters['district_uuid'] ?? null)) {
            $district = $this->resolveModelByUuid(District::class, $filters['district_uuid']);
            if (!$district) {
                return ApiResponse::error('District not found', ['district_uuid' => ['Invalid district identifier']], 422);
            }

            $query->where('district_id', $district->id);
        }

        if (!empty($filters['ward_uuid'] ?? null)) {
            $ward = $this->resolveModelByUuid(Ward::class, $filters['ward_uuid']);
            if (!$ward) {
                return ApiResponse::error('Ward not found', ['ward_uuid' => ['Invalid ward identifier']], 422);
            }

            $query->where('ward_id', $ward->id);
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
        $this->attachPropertyListSubscriptionSnapshots($properties);

        return ApiResponse::resource(PropertyResource::collection($properties), 'Properties list');
    }

    /**
     * Handle the store request.
     */
    public function store(StorePropertyRequest $request)
    {
        $this->authorize('create', Property::class);
        $this->prepareInventoryMutation(true);

        $data = $request->validated();
        $type = null;
        $location = null;
        if (!empty($data['type_uuid'] ?? null)) {
            $type = $this->resolveModelByUuid(PropertyType::class, $data['type_uuid']);
            if (!$type) {
                return ApiResponse::error('Property type not found', ['type_uuid' => ['Invalid property type identifier']], 422);
            }
        }
        $location = $this->resolvePropertyLocation($data);
        if ($location instanceof JsonResponse) {
            return $location;
        }

        $exists = Property::query()->where('name', $data['name'])->exists();

        if ($exists) {
            return ApiResponse::error('A property with this name already exists', ['name' => ['Duplicate property name']], 422);
        }

        $property = DB::transaction(fn () => Property::query()->create([
            'name' => trim($data['name']),
            'type_id' => $type?->id,
            'country_id' => $location['country']?->id,
            'region_id' => $location['region']?->id,
            'district_id' => $location['district']?->id,
            'ward_id' => $location['ward']?->id,
            'address_line' => $data['address_line'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]));

        $this->syncWorkspaceUsage();
        $this->syncWorkspacePropertyRegistry([(int) $property->id]);

        return ApiResponse::resource(new PropertyResource($property->load(['type', 'country', 'region.country', 'district.region.country', 'ward'])->loadCount(['floors', 'units'])), 'Property created', 201);
    }

    /**
     * Handle the show request.
     */
    public function show(Property $property)
    {
        $this->authorize('view', $property);

        return ApiResponse::resource(
            new PropertyResource($this->loadPropertyDetails($property)),
            'Property details'
        );
    }

    /**
     * Handle the update request.
     */
    public function update(UpdatePropertyRequest $request, Property $property)
    {
        $this->authorize('update', $property);
        $this->prepareInventoryMutation();

        $data = $request->validated();
        $typeId = $property->type_id;
        $countryId = $property->country_id;
        $regionId = $property->region_id;
        $districtId = $property->district_id;
        $wardId = $property->ward_id;

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

        $countryChanged = array_key_exists('country_uuid', $data);
        $regionChanged = array_key_exists('region_uuid', $data);
        $districtChanged = array_key_exists('district_uuid', $data);
        $wardChanged = array_key_exists('ward_uuid', $data);

        $locationInput = [
            'country_uuid' => $countryChanged ? $data['country_uuid'] : $property->country?->uuid,
            'region_uuid' => $regionChanged
                ? $data['region_uuid']
                : ($countryChanged ? null : $property->region?->uuid),
            'district_uuid' => $districtChanged
                ? $data['district_uuid']
                : (($countryChanged || $regionChanged) ? null : $property->district?->uuid),
            'ward_uuid' => $wardChanged
                ? $data['ward_uuid']
                : (($countryChanged || $regionChanged || $districtChanged) ? null : $property->ward?->uuid),
        ];

        $location = $this->resolvePropertyLocation($locationInput);
        if ($location instanceof JsonResponse) {
            return $location;
        }

        $countryId = $location['country']?->id;
        $regionId = $location['region']?->id;
        $districtId = $location['district']?->id;
        $wardId = $location['ward']?->id;

        $name = isset($data['name']) ? trim($data['name']) : $property->name;
        $exists = Property::query()
            ->where('name', $name)
            ->whereKeyNot($property->id)
            ->exists();

        if ($exists) {
            return ApiResponse::error('A property with this name already exists', ['name' => ['Duplicate property name']], 422);
        }

        DB::transaction(function () use ($property, $name, $typeId, $countryId, $regionId, $districtId, $wardId, $data) {
            $property->fill([
                'name' => $name,
                'type_id' => $typeId,
                'country_id' => $countryId,
                'region_id' => $regionId,
                'district_id' => $districtId,
                'ward_id' => $wardId,
                'address_line' => array_key_exists('address_line', $data) ? $data['address_line'] : $property->address_line,
                'postal_code' => array_key_exists('postal_code', $data) ? $data['postal_code'] : $property->postal_code,
                'status' => $data['status'] ?? $property->status,
            ])->save();
        });

        $this->syncWorkspacePropertyRegistry([(int) $property->id]);

        return ApiResponse::resource(
            new PropertyResource($property->fresh()->load(['type', 'country', 'region.country', 'district.region.country', 'ward'])->loadCount(['floors', 'units'])),
            'Property updated'
        );
    }

    /**
     * Handle the destroy request.
     */
    public function destroy(Property $property)
    {
        $this->authorize('delete', $property);
        $this->prepareInventoryMutation(true);
        $this->syncWorkspacePropertyRegistry([(int) $property->id]);

        DB::transaction(fn () => $property->delete());

        $this->syncWorkspaceUsage();
        $this->markWorkspacePropertyDeleted($property->uuid);

        return ApiResponse::success('Property deleted');
    }

    /**
     * Sync workspace usage.
     */
    private function syncWorkspaceUsage(): void
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            $this->subscriptionService->syncWorkspaceUsage($tenant);
            $this->subscriptionUsageAdjustmentService->syncPendingAdjustment($tenant);
        }
    }

    /**
     * Prepare inventory mutation.
     */
    private function prepareInventoryMutation(bool $captureUsageBaseline = false): void
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            $this->subscriptionService->assertWorkspaceAllowsInventoryMutation($tenant);
            if ($captureUsageBaseline) {
                $this->subscriptionUsageAdjustmentService->prepareInventoryMutation($tenant);
            }
        }
    }

    /**
     * Sync workspace property registry.
     */
    private function syncWorkspacePropertyRegistry(array $propertyIds): void
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            $this->workspacePropertyRegistryService->syncPropertyIds($tenant, $propertyIds);
        }
    }

    /**
     * Mark workspace property deleted.
     */
    private function markWorkspacePropertyDeleted(string $propertyUuid): void
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            $this->workspacePropertyRegistryService->markPropertyDeleted($tenant, $propertyUuid);
        }
    }

    /**
     * Validate property location hierarchy.
     */
    private function validatePropertyLocationHierarchy(
        ?Country $country,
        ?Region $region,
        ?District $district,
        ?Ward $ward
    ): ?JsonResponse {
        if ($region && $country && $region->country_id !== $country->id) {
            return ApiResponse::error(
                'Region does not belong to the selected country',
                ['region_uuid' => ['The selected region does not belong to the selected country.']],
                422
            );
        }

        if ($district && $region && $district->region_id !== $region->id) {
            return ApiResponse::error(
                'District does not belong to the selected region',
                ['district_uuid' => ['The selected district does not belong to the selected region.']],
                422
            );
        }

        if ($district && $country && $district->region?->country_id !== $country->id) {
            return ApiResponse::error(
                'District does not belong to the selected country',
                ['district_uuid' => ['The selected district does not belong to the selected country.']],
                422
            );
        }

        if ($ward && $district && $ward->district_id !== $district->id) {
            return ApiResponse::error(
                'Ward does not belong to the selected district',
                ['ward_uuid' => ['The selected ward does not belong to the selected district.']],
                422
            );
        }

        if ($ward && $region && $ward->district?->region_id !== $region->id) {
            return ApiResponse::error(
                'Ward does not belong to the selected region',
                ['ward_uuid' => ['The selected ward does not belong to the selected region.']],
                422
            );
        }

        if ($ward && $country && $ward->district?->region?->country_id !== $country->id) {
            return ApiResponse::error(
                'Ward does not belong to the selected country',
                ['ward_uuid' => ['The selected ward does not belong to the selected country.']],
                422
            );
        }

        return null;
    }

    /**
     * Resolve property location.
     */
    private function resolvePropertyLocation(array $data): array|JsonResponse
    {
        $country = null;
        $region = null;
        $district = null;
        $ward = null;

        if (!empty($data['country_uuid'] ?? null)) {
            $country = $this->resolveModelByUuid(Country::class, $data['country_uuid']);
            if (!$country) {
                return ApiResponse::error('Country not found', ['country_uuid' => ['Invalid country identifier']], 422);
            }
        }

        if (!empty($data['region_uuid'] ?? null)) {
            $region = Region::query()
                ->select(['id', 'uuid', 'country_id', 'name'])
                ->with('country:id,uuid,name,code')
                ->where('uuid', $data['region_uuid'])
                ->first();

            if (!$region) {
                return ApiResponse::error('Region not found', ['region_uuid' => ['Invalid region identifier']], 422);
            }

            $country ??= $region->country;
        }

        if (!empty($data['district_uuid'] ?? null)) {
            $district = District::query()
                ->select(['id', 'uuid', 'region_id', 'name'])
                ->with('region:id,uuid,country_id,name', 'region.country:id,uuid,name,code')
                ->where('uuid', $data['district_uuid'])
                ->first();

            if (!$district) {
                return ApiResponse::error('District not found', ['district_uuid' => ['Invalid district identifier']], 422);
            }

            $region ??= $district->region;
            $country ??= $district->region?->country;
        }

        if (!empty($data['ward_uuid'] ?? null)) {
            $ward = Ward::query()
                ->select(['id', 'uuid', 'district_id', 'name'])
                ->with('district:id,uuid,region_id,name', 'district.region:id,uuid,country_id,name', 'district.region.country:id,uuid,name,code')
                ->where('uuid', $data['ward_uuid'])
                ->first();

            if (!$ward) {
                return ApiResponse::error('Ward not found', ['ward_uuid' => ['Invalid ward identifier']], 422);
            }

            $district ??= $ward->district;
            $region ??= $ward->district?->region;
            $country ??= $ward->district?->region?->country;
        }

        $hierarchyValidation = $this->validatePropertyLocationHierarchy($country, $region, $district, $ward);
        if ($hierarchyValidation !== null) {
            return $hierarchyValidation;
        }

        $requiredLevelValidation = $this->validateRequiredLocationDepth($country, $region, $district, $ward);
        if ($requiredLevelValidation !== null) {
            return $requiredLevelValidation;
        }

        return [
            'country' => $country,
            'region' => $region,
            'district' => $district,
            'ward' => $ward,
        ];
    }

    /**
     * Validate required location depth.
     */
    private function validateRequiredLocationDepth(
        ?Country $country,
        ?Region $region,
        ?District $district,
        ?Ward $ward
    ): ?JsonResponse {
        if (!$country) {
            return null;
        }

        if (!$region && Region::query()->where('country_id', $country->id)->exists()) {
            return ApiResponse::error(
                'Property location is incomplete',
                ['region_uuid' => ['Select a region for the selected country.']],
                422
            );
        }

        if ($region && !$district && District::query()->where('region_id', $region->id)->exists()) {
            return ApiResponse::error(
                'Property location is incomplete',
                ['district_uuid' => ['Select a district for the selected region.']],
                422
            );
        }

        if ($district && !$ward && Ward::query()->where('district_id', $district->id)->exists()) {
            return ApiResponse::error(
                'Property location is incomplete',
                ['ward_uuid' => ['Select a ward for the selected district.']],
                422
            );
        }

        return null;
    }

    /**
     * Load property details.
     */
    private function loadPropertyDetails(Property $property): Property
    {
        $tenant = request()->attributes->get('tenant');

        $property->load(['type', 'country', 'region.country', 'district.region.country', 'ward'])
            ->loadCount(['floors', 'units']);

        $metrics = $this->propertyMetricsService->forProperty((int) $property->id);

        $property->setAttribute('occupied_count', (int) ($metrics['occupied_count'] ?? 0));
        $property->setAttribute('vacant_count', (int) ($metrics['vacant_count'] ?? 0));
        $property->setAttribute('maintenance_count', (int) ($metrics['maintenance_count'] ?? 0));
        $property->setAttribute('contracts_count', (int) ($metrics['contracts_count'] ?? 0));
        $property->setAttribute('contract_statuses', $metrics['contract_statuses'] ?? []);
        $property->setAttribute(
            'access',
            $tenant instanceof Tenant
                ? $this->propertySubscriptionAccessService->accessSummary($tenant, $property)
                : null
        );

        return $property;
    }

    /**
     * Attach property list subscription snapshots.
     */
    private function attachPropertyListSubscriptionSnapshots($paginator): void
    {
        $tenant = request()->attributes->get('tenant');

        if (!$tenant instanceof Tenant) {
            return;
        }

        $collection = $paginator->getCollection();
        $snapshots = $this->propertySubscriptionAccessService->propertyListSnapshotMap($tenant, $collection);

        $collection->transform(function (Property $property) use ($snapshots) {
            $snapshot = $snapshots[$property->uuid] ?? null;

            if (!$snapshot) {
                return $property;
            }

            $property->setAttribute('property_status', $snapshot['property_status'] ?? $property->status);
            $property->setAttribute('subscription_status', $snapshot['subscription_status'] ?? null);
            $property->setAttribute('subscription_message', $snapshot['subscription_message'] ?? null);
            $property->setAttribute('subscription_reason_code', $snapshot['subscription_reason_code'] ?? null);
            $property->setAttribute('payment_required_now', (bool) ($snapshot['payment_required_now'] ?? false));
            $property->setAttribute('operations_allowed', (bool) ($snapshot['operations_allowed'] ?? false));
            $property->setAttribute('operations_message', $snapshot['operations_message'] ?? null);
            $property->setAttribute('operations_reason_code', $snapshot['operations_reason_code'] ?? null);
            $property->setAttribute('subscription_current_period_ends_on', $snapshot['current_period_ends_on'] ?? null);
            $property->setAttribute('subscription_expired_on', $snapshot['expired_on'] ?? null);

            return $property;
        });
    }
}
