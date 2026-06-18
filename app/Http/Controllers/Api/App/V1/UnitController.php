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
use App\Models\Tenancy\Tenant;
use App\Services\V1\Billing\PropertySubscriptionAccessService;
use App\Services\V1\Billing\SubscriptionUsageAdjustmentService;
use App\Services\V1\Billing\WorkspacePropertyRegistryService;
use App\Services\V1\PropertyAssignmentAccessService;
use App\Services\V1\SubscriptionService;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UnitController extends Controller
{
    use InteractsWithTenantModels;

    /**
     * Create a new instance.
     */
    public function __construct(
        private SubscriptionService $subscriptionService,
        private SubscriptionUsageAdjustmentService $subscriptionUsageAdjustmentService,
        private PropertySubscriptionAccessService $propertySubscriptionAccessService,
        private WorkspacePropertyRegistryService $workspacePropertyRegistryService,
        private PropertyAssignmentAccessService $propertyAssignmentAccessService,
    )
    {
    }

    /**
     * Handle the index request.
     */
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

            /* Resolve floor IDs once ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â avoids nested whereHas subquery */
            $floorIds = PropertyFloor::query()
                ->where('property_id', $property->id)
                ->pluck('id')
                ->all();

            if ($floorIds === []) {
                return ApiResponse::resource(UnitResource::collection(collect()), ApiMessages::listRetrieved('units'));
            }

            $query->whereIn('property_floor_id', $floorIds);
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

        $units = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(UnitResource::collection($units), ApiMessages::listRetrieved('units'));
    }

    /**
     * Handle the store request.
     */
    public function store(StoreUnitRequest $request)
    {
        $this->authorize('create', Unit::class);
        $this->prepareInventoryMutation(true);

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

        $property = $propertyFloor->loadMissing('property')->property;
        if ($error = $this->assertPropertyAllowsOperationalMutation($property, 'units')) {
            return $error;
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

        $this->syncWorkspaceUsage([(int) $property->id]);

        return ApiResponse::resource(new UnitResource($unit->load('propertyFloor.property')), ApiMessages::created('unit'), 201);
    }

    /**
     * Handle the show request.
     */
    public function show(Unit $unit)
    {
        $this->authorize('view', $unit);

        return ApiResponse::resource(new UnitResource($unit->load('propertyFloor.property')), ApiMessages::detailsRetrieved('unit'));
    }

    /**
     * Handle the update request.
     */
    public function update(UpdateUnitRequest $request, Unit $unit)
    {
        $this->authorize('update', $unit);
        $this->prepareInventoryMutation();

        $data = $request->validated();
        $originalPropertyId = (int) $unit->propertyFloor->property_id;
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

        $property = $propertyFloor->loadMissing('property')->property;
        if ($error = $this->assertPropertyAllowsOperationalMutation($property, 'units')) {
            return $error;
        }

        if ($originalPropertyId !== (int) $propertyFloor->property_id) {
            $this->captureUsageBaseline();
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

        if ($originalPropertyId !== (int) $propertyFloor->property_id) {
            $this->syncWorkspaceUsage([$originalPropertyId, (int) $propertyFloor->property_id]);
        }

        return ApiResponse::resource(new UnitResource($unit->fresh()->load('propertyFloor.property')), ApiMessages::updated('unit'));
    }

    /**
     * Handle the destroy request.
     */
    public function destroy(Unit $unit)
    {
        $this->authorize('delete', $unit);
        $this->prepareInventoryMutation(true);
        $property = $unit->loadMissing('propertyFloor.property')->propertyFloor->property;

        if ($error = $this->assertPropertyAllowsOperationalMutation($property, 'units')) {
            return $error;
        }

        DB::transaction(fn () => $unit->delete());

        $this->syncWorkspaceUsage([(int) $property->id]);

        return ApiResponse::success(ApiMessages::deleted('unit'));
    }

    /**
     * Sync workspace usage.
     */
    private function syncWorkspaceUsage(array $propertyIds = []): void
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            $this->subscriptionService->syncWorkspaceUsage($tenant);
            $this->subscriptionUsageAdjustmentService->syncPendingAdjustment($tenant);
            if ($propertyIds !== []) {
                $this->workspacePropertyRegistryService->syncPropertyIds($tenant, $propertyIds);
            }
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
     * Capture usage baseline.
     */
    private function captureUsageBaseline(): void
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            $this->subscriptionUsageAdjustmentService->prepareInventoryMutation($tenant);
        }
    }

    /**
     * Assert property allows operational mutation.
     */
    private function assertPropertyAllowsOperationalMutation(Property $property, string $moduleName): ?\Illuminate\Http\JsonResponse
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            try {
                $this->propertySubscriptionAccessService->assertPropertyAllowsOperationalMutation($tenant, $property, $moduleName);
            } catch (InvalidArgumentException $exception) {
                return ApiResponse::error(
                    'Property subscription access is required.',
                    ['property_subscription' => [$exception->getMessage()]],
                    422
                );
            }
        }

        return null;
    }
}
