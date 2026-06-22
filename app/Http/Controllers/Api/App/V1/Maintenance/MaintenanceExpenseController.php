<?php

namespace App\Http\Controllers\Api\App\V1\Maintenance;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\Maintenance\MaintenanceExpenseIndexRequest;
use App\Http\Requests\Api\App\V1\Maintenance\StoreMaintenanceExpenseRequest;
use App\Http\Requests\Api\App\V1\Maintenance\UpdateMaintenanceExpenseRequest;
use App\Http\Resources\App\V1\Maintenance\MaintenanceExpenseResource;
use App\Models\Tenant\MaintenanceExpense;
use App\Models\Tenant\MaintenanceJob;
use App\Models\Tenant\Property;
use App\Models\Tenant\PropertyFloor;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenancy\Tenant;
use App\Services\V1\Billing\PropertySubscriptionAccessService;
use App\Services\V1\PropertyAssignmentAccessService;
use App\Services\V1\SubscriptionService;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MaintenanceExpenseController extends Controller
{
    use InteractsWithTenantModels;

    /**
     * Create a new instance.
     */
    public function __construct(
        private PropertyAssignmentAccessService $propertyAssignmentAccessService,
        private PropertySubscriptionAccessService $propertySubscriptionAccessService,
        private SubscriptionService $subscriptionService,
    ) {
    }

    /**
     * Handle the index request.
     */
    public function index(MaintenanceExpenseIndexRequest $request)
    {
        $this->authorize('viewAny', MaintenanceExpense::class);

        $filters = $request->validated();
        $tenantUser = request()->user();
        $query = MaintenanceExpense::query()
            ->select('maintenance_expenses.*')
            ->join('maintenance_jobs', 'maintenance_jobs.id', '=', 'maintenance_expenses.maintenance_job_id')
            ->with([
                'maintenanceJob.property:id,uuid,name',
                'maintenanceJob.propertyFloor:id,uuid,property_id,name,floor_number',
                'maintenanceJob.unit:id,uuid,property_floor_id,unit_number,status',
                'recordedBy:id,uuid,name,email',
            ]);

        if ($tenantUser instanceof TenantUser) {
            $this->propertyAssignmentAccessService->scopeMaintenanceExpenses($query, $tenantUser, 'maintenance_jobs.property_id');
        }

        if (!empty($filters['maintenance_job_uuid'] ?? null)) {
            $query->where('maintenance_jobs.uuid', $filters['maintenance_job_uuid']);
        }

        if (!empty($filters['property_uuid'] ?? null)) {
            $property = $this->resolveModelByUuid(Property::class, $filters['property_uuid']);
            if (!$property) {
                return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
            }

            $query->where('maintenance_jobs.property_id', $property->id);
        }

        if (!empty($filters['property_floor_uuid'] ?? null)) {
            $propertyFloor = $this->resolveModelByUuid(PropertyFloor::class, $filters['property_floor_uuid']);
            if (!$propertyFloor) {
                return ApiResponse::error('Property floor not found', ['property_floor_uuid' => ['Invalid floor identifier']], 422);
            }

            $query->where('maintenance_jobs.property_floor_id', $propertyFloor->id);
        }

        if (!empty($filters['unit_uuid'] ?? null)) {
            $unit = $this->resolveModelByUuid(Unit::class, $filters['unit_uuid']);
            if (!$unit) {
                return ApiResponse::error('Unit not found', ['unit_uuid' => ['Invalid unit identifier']], 422);
            }

            $query->where('maintenance_jobs.unit_id', $unit->id);
        }

        if (!empty($filters['search'] ?? null)) {
            $query->where(function ($innerQuery) use ($filters) {
                $innerQuery
                    ->where('maintenance_expenses.title', 'like', $filters['search'].'%')
                    ->orWhere('maintenance_jobs.title', 'like', $filters['search'].'%');
            });
        }

        if (!empty($filters['start_date'] ?? null) || !empty($filters['end_date'] ?? null)) {
            $startDate = $filters['start_date'] ?? $filters['end_date'];
            $endDate = $filters['end_date'] ?? $filters['start_date'];
            $query->whereBetween('maintenance_expenses.expense_date', [$startDate, $endDate]);
        }

        $this->applyIndexSort($query, $filters['sort'] ?? null);

        $expenses = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(MaintenanceExpenseResource::collection($expenses), ApiMessages::listRetrieved('maintenance_expenses'));
    }

    /**
     * Handle the store request.
     */
    public function store(StoreMaintenanceExpenseRequest $request)
    {
        $this->authorize('create', MaintenanceExpense::class);
        $this->assertWorkspaceAllowsInventoryMutation();

        $data = $request->validated();
        $maintenanceJob = $this->resolveMaintenanceJobByUuid($data['maintenance_job_uuid']);
        if (!$maintenanceJob) {
            return ApiResponse::error('Maintenance job not found', ['maintenance_job_uuid' => ['Invalid maintenance job identifier']], 422);
        }

        $tenantUser = request()->user();
        if ($tenantUser instanceof TenantUser
            && !$this->propertyAssignmentAccessService->canAccessMaintenanceJobModel($tenantUser, $maintenanceJob)) {
            return ApiResponse::forbidden(['maintenance_job' => ['You do not have access to the selected maintenance job.']]);
        }

        $property = Property::query()->find($maintenanceJob->property_id);
        if (!$property) {
            return ApiResponse::error('Property not found', ['maintenance_job_uuid' => ['The selected maintenance job is not attached to a valid property.']], 422);
        }

        if ($error = $this->assertPropertyAllowsMaintenance($property)) {
            return $error;
        }

        $maintenanceExpense = DB::transaction(function () use ($data, $maintenanceJob, $tenantUser) {
            return MaintenanceExpense::query()->create([
                'maintenance_job_id' => $maintenanceJob->id,
                'title' => $this->normalizeTitle($data['title']),
                'description' => $this->normalizeDescription($data['description'] ?? null),
                'amount' => $data['amount'],
                'expense_date' => $data['expense_date'] ?? now()->toDateString(),
                'recorded_by' => $tenantUser instanceof TenantUser ? $tenantUser->id : null,
            ]);
        });

        return ApiResponse::resource(
            new MaintenanceExpenseResource($this->reloadMaintenanceExpense($maintenanceExpense)),
            ApiMessages::created('maintenance_expense'),
            201
        );
    }

    /**
     * Handle the show request.
     */
    public function show(MaintenanceExpense $maintenanceExpense)
    {
        $this->authorize('view', $maintenanceExpense);

        return ApiResponse::resource(
            new MaintenanceExpenseResource($this->reloadMaintenanceExpense($maintenanceExpense)),
            ApiMessages::detailsRetrieved('maintenance_expense')
        );
    }

    /**
     * Handle the update request.
     */
    public function update(UpdateMaintenanceExpenseRequest $request, MaintenanceExpense $maintenanceExpense)
    {
        $this->authorize('update', $maintenanceExpense);
        $this->assertWorkspaceAllowsInventoryMutation();

        $data = $request->validated();
        $maintenanceJob = array_key_exists('maintenance_job_uuid', $data)
            ? $this->resolveMaintenanceJobByUuid($data['maintenance_job_uuid'])
            : $maintenanceExpense->maintenanceJob;

        if (!$maintenanceJob) {
            return ApiResponse::error('Maintenance job not found', ['maintenance_job_uuid' => ['Invalid maintenance job identifier']], 422);
        }

        $tenantUser = request()->user();
        if ($tenantUser instanceof TenantUser
            && !$this->propertyAssignmentAccessService->canAccessMaintenanceJobModel($tenantUser, $maintenanceJob)) {
            return ApiResponse::forbidden(['maintenance_job' => ['You do not have access to the selected maintenance job.']]);
        }

        $property = Property::query()->find($maintenanceJob->property_id);
        if (!$property) {
            return ApiResponse::error('Property not found', ['maintenance_job_uuid' => ['The selected maintenance job is not attached to a valid property.']], 422);
        }

        if ($error = $this->assertPropertyAllowsMaintenance($property)) {
            return $error;
        }

        DB::transaction(function () use ($maintenanceExpense, $maintenanceJob, $data) {
            $maintenanceExpense->fill([
                'maintenance_job_id' => $maintenanceJob->id,
                'title' => array_key_exists('title', $data) ? $this->normalizeTitle($data['title']) : $maintenanceExpense->title,
                'description' => array_key_exists('description', $data) ? $this->normalizeDescription($data['description']) : $maintenanceExpense->description,
                'amount' => $data['amount'] ?? $maintenanceExpense->amount,
                'expense_date' => $data['expense_date'] ?? $maintenanceExpense->expense_date,
            ])->save();
        });

        return ApiResponse::resource(
            new MaintenanceExpenseResource($this->reloadMaintenanceExpense($maintenanceExpense)),
            ApiMessages::updated('maintenance_expense')
        );
    }

    /**
     * Handle the destroy request.
     */
    public function destroy(MaintenanceExpense $maintenanceExpense)
    {
        $this->authorize('delete', $maintenanceExpense);
        $this->assertWorkspaceAllowsInventoryMutation();
        $property = $maintenanceExpense->loadMissing('maintenanceJob.property')->maintenanceJob?->property;

        if ($property && ($error = $this->assertPropertyAllowsMaintenance($property))) {
            return $error;
        }

        DB::transaction(fn () => $maintenanceExpense->delete());

        return ApiResponse::success(ApiMessages::deleted('maintenance_expense'));
    }

    /**
     * Resolve maintenance job by uuid.
     */
    private function resolveMaintenanceJobByUuid(string $uuid): ?MaintenanceJob
    {
        return MaintenanceJob::query()
            ->select(['id', 'uuid', 'property_id', 'property_floor_id', 'unit_id', 'title', 'status', 'reported_date'])
            ->where('uuid', $uuid)
            ->first();
    }

    /**
     * Reload maintenance expense.
     */
    private function reloadMaintenanceExpense(MaintenanceExpense $maintenanceExpense): MaintenanceExpense
    {
        return MaintenanceExpense::query()
            ->with([
                'maintenanceJob.property:id,uuid,name',
                'maintenanceJob.propertyFloor:id,uuid,property_id,name,floor_number',
                'maintenanceJob.unit:id,uuid,property_floor_id,unit_number,status',
                'recordedBy:id,uuid,name,email',
            ])
            ->findOrFail($maintenanceExpense->id);
    }

    /**
     * Normalize title.
     */
    private function normalizeTitle(string $value): string
    {
        return Str::of($value)->trim()->squish()->ucfirst()->toString();
    }

    /**
     * Normalize description.
     */
    private function normalizeDescription(?string $value): ?string
    {
        $normalized = Str::of((string) $value)->trim()->squish()->toString();

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Apply index sort.
     */
    private function applyIndexSort(\Illuminate\Database\Eloquent\Builder $query, ?string $sort): void
    {
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $column = ltrim((string) $sort, '-');

        match ($column) {
            'amount' => $query->orderBy('maintenance_expenses.amount', $direction)->orderBy('maintenance_expenses.expense_date', 'desc'),
            'title' => $query->orderBy('maintenance_expenses.title', $direction)->orderBy('maintenance_expenses.expense_date', 'desc'),
            'created_at' => $query->orderBy('maintenance_expenses.created_at', $direction)->orderBy('maintenance_expenses.id', 'desc'),
            'expense_date', '' => $query->orderBy('maintenance_expenses.expense_date', $direction)->orderBy('maintenance_expenses.id', 'desc'),
            default => $query->orderBy('maintenance_expenses.expense_date', 'desc')->orderBy('maintenance_expenses.id', 'desc'),
        };
    }

    /**
     * Assert workspace allows inventory mutation.
     */
    private function assertWorkspaceAllowsInventoryMutation(): void
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            $this->subscriptionService->assertWorkspaceAllowsInventoryMutation($tenant);
        }
    }

    /**
     * Assert property allows maintenance.
     */
    private function assertPropertyAllowsMaintenance(Property $property): ?\Illuminate\Http\JsonResponse
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            try {
                $this->propertySubscriptionAccessService->assertPropertyAllowsOperationalMutation($tenant, $property, 'maintenance');
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
