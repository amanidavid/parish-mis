<?php

namespace App\Http\Controllers\Api\App\V1\Maintenance;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\Maintenance\MaintenanceJobIndexRequest;
use App\Http\Requests\Api\App\V1\Maintenance\StoreMaintenanceJobRequest;
use App\Http\Requests\Api\App\V1\Maintenance\UpdateMaintenanceJobRequest;
use App\Http\Resources\App\V1\Maintenance\MaintenanceJobResource;
use App\Models\Tenant\MaintenanceJob;
use App\Models\Tenant\Property;
use App\Models\Tenant\PropertyFloor;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User as TenantUser;
use App\Services\V1\Maintenance\MaintenanceHierarchyService;
use App\Services\V1\PropertyAssignmentAccessService;
use App\Services\V1\SubscriptionService;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MaintenanceJobController extends Controller
{
    use InteractsWithTenantModels;

    public function __construct(
        private MaintenanceHierarchyService $maintenanceHierarchyService,
        private PropertyAssignmentAccessService $propertyAssignmentAccessService,
        private SubscriptionService $subscriptionService,
    ) {
    }

    public function index(MaintenanceJobIndexRequest $request)
    {
        $this->authorize('viewAny', MaintenanceJob::class);

        $filters = $request->validated();
        $tenantUser = request()->user();
        $query = MaintenanceJob::query()
            ->with([
                'property:id,uuid,name',
                'propertyFloor:id,uuid,property_id,name,floor_number',
                'unit:id,uuid,property_floor_id,unit_number,status',
                'recordedBy:id,uuid,name,email',
            ])
            ->withCount('expenses')
            ->withSum('expenses as total_expense_amount', 'amount');

        if ($tenantUser instanceof TenantUser) {
            $this->propertyAssignmentAccessService->scopeMaintenanceJobs($query, $tenantUser, 'property_id');
        }

        if (!empty($filters['property_uuid'] ?? null)) {
            $property = $this->resolveModelByUuid(Property::class, $filters['property_uuid']);
            if (!$property) {
                return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
            }

            $query->where('property_id', $property->id);
        }

        if (!empty($filters['property_floor_uuid'] ?? null)) {
            $propertyFloor = $this->resolveModelByUuid(PropertyFloor::class, $filters['property_floor_uuid']);
            if (!$propertyFloor) {
                return ApiResponse::error('Property floor not found', ['property_floor_uuid' => ['Invalid floor identifier']], 422);
            }

            $query->where('property_floor_id', $propertyFloor->id);
        }

        if (!empty($filters['unit_uuid'] ?? null)) {
            $unit = $this->resolveModelByUuid(Unit::class, $filters['unit_uuid']);
            if (!$unit) {
                return ApiResponse::error('Unit not found', ['unit_uuid' => ['Invalid unit identifier']], 422);
            }

            $query->where('unit_id', $unit->id);
        }

        if (!empty($filters['search'] ?? null)) {
            $this->applyPrefixSearch($query, $filters['search'], ['title']);
        }

        if (!empty($filters['start_date'] ?? null) || !empty($filters['end_date'] ?? null)) {
            $startDate = $filters['start_date'] ?? $filters['end_date'];
            $endDate = $filters['end_date'] ?? $filters['start_date'];
            $query->whereBetween('reported_date', [$startDate, $endDate]);
        }

        $this->applySort($query, $filters['sort'] ?? null, ['reported_date', 'title', 'created_at', 'total_expense_amount'], 'reported_date', 'desc');

        $jobs = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(MaintenanceJobResource::collection($jobs), ApiMessages::listRetrieved('maintenance_jobs'));
    }

    public function store(StoreMaintenanceJobRequest $request)
    {
        $this->authorize('create', MaintenanceJob::class);
        $this->assertWorkspaceAllowsInventoryMutation();

        $data = $request->validated();
        $resolvedHierarchy = $this->maintenanceHierarchyService->resolveJobHierarchy($data);
        if ($resolvedHierarchy instanceof \Illuminate\Http\JsonResponse) {
            return $resolvedHierarchy;
        }

        $tenantUser = request()->user();
        if ($tenantUser instanceof TenantUser) {
            $accessError = $this->maintenanceHierarchyService->ensurePropertyAccess($tenantUser, $resolvedHierarchy['property']);
            if ($accessError) {
                return $accessError;
            }
        }

        $maintenanceJob = DB::transaction(function () use ($data, $resolvedHierarchy, $tenantUser) {
            return MaintenanceJob::query()->create([
                'property_id' => $resolvedHierarchy['property']->id,
                'property_floor_id' => $resolvedHierarchy['property_floor']?->id,
                'unit_id' => $resolvedHierarchy['unit']?->id,
                'title' => $this->normalizeTitle($data['title']),
                'description' => $this->normalizeDescription($data['description'] ?? null),
                'reported_date' => $data['reported_date'] ?? now()->toDateString(),
                'recorded_by' => $tenantUser instanceof TenantUser ? $tenantUser->id : null,
            ]);
        });

        return ApiResponse::resource(
            new MaintenanceJobResource($this->reloadMaintenanceJob($maintenanceJob)),
            ApiMessages::created('maintenance_job'),
            201
        );
    }

    public function show(MaintenanceJob $maintenanceJob)
    {
        $this->authorize('view', $maintenanceJob);

        return ApiResponse::resource(
            new MaintenanceJobResource($this->reloadMaintenanceJob($maintenanceJob, true)),
            ApiMessages::detailsRetrieved('maintenance_job')
        );
    }

    public function update(UpdateMaintenanceJobRequest $request, MaintenanceJob $maintenanceJob)
    {
        $this->authorize('update', $maintenanceJob);
        $this->assertWorkspaceAllowsInventoryMutation();

        $data = $request->validated();
        $resolvedHierarchy = $this->maintenanceHierarchyService->resolveJobHierarchyForUpdate($maintenanceJob->loadMissing(['property', 'propertyFloor', 'unit']), $data);
        if ($resolvedHierarchy instanceof \Illuminate\Http\JsonResponse) {
            return $resolvedHierarchy;
        }

        $tenantUser = request()->user();
        if ($tenantUser instanceof TenantUser) {
            $accessError = $this->maintenanceHierarchyService->ensurePropertyAccess($tenantUser, $resolvedHierarchy['property']);
            if ($accessError) {
                return $accessError;
            }
        }

        DB::transaction(function () use ($maintenanceJob, $data, $resolvedHierarchy) {
            $maintenanceJob->fill([
                'property_id' => $resolvedHierarchy['property']->id,
                'property_floor_id' => $resolvedHierarchy['property_floor']?->id,
                'unit_id' => $resolvedHierarchy['unit']?->id,
                'title' => array_key_exists('title', $data) ? $this->normalizeTitle($data['title']) : $maintenanceJob->title,
                'description' => array_key_exists('description', $data) ? $this->normalizeDescription($data['description']) : $maintenanceJob->description,
                'reported_date' => $data['reported_date'] ?? $maintenanceJob->reported_date,
            ])->save();
        });

        return ApiResponse::resource(
            new MaintenanceJobResource($this->reloadMaintenanceJob($maintenanceJob)),
            ApiMessages::updated('maintenance_job')
        );
    }

    public function destroy(MaintenanceJob $maintenanceJob)
    {
        $this->authorize('delete', $maintenanceJob);
        $this->assertWorkspaceAllowsInventoryMutation();

        DB::transaction(fn () => $maintenanceJob->delete());

        return ApiResponse::success(ApiMessages::deleted('maintenance_job'));
    }

    private function reloadMaintenanceJob(MaintenanceJob $maintenanceJob, bool $includeExpenses = false): MaintenanceJob
    {
        $query = MaintenanceJob::query()
            ->with([
                'property:id,uuid,name',
                'propertyFloor:id,uuid,property_id,name,floor_number',
                'unit:id,uuid,property_floor_id,unit_number,status',
                'recordedBy:id,uuid,name,email',
            ])
            ->withCount('expenses')
            ->withSum('expenses as total_expense_amount', 'amount');

        if ($includeExpenses) {
            $query->with([
                'expenses' => fn ($expenseQuery) => $expenseQuery
                    ->with('recordedBy:id,uuid,name,email')
                    ->orderByDesc('expense_date')
                    ->orderByDesc('id'),
            ]);
        }

        return $query->findOrFail($maintenanceJob->id);
    }

    private function normalizeTitle(string $value): string
    {
        $normalized = Str::of($value)->trim()->squish()->ucfirst()->toString();

        return $normalized;
    }

    private function normalizeDescription(?string $value): ?string
    {
        $normalized = Str::of((string) $value)->trim()->squish()->toString();

        return $normalized !== '' ? $normalized : null;
    }

    private function assertWorkspaceAllowsInventoryMutation(): void
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof \App\Models\Tenancy\Tenant) {
            $this->subscriptionService->assertWorkspaceAllowsInventoryMutation($tenant);
        }
    }
}
