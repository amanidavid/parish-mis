<?php

namespace App\Http\Controllers\Api\App\V1\Maintenance;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\Maintenance\DailyPropertyExpenseIndexRequest;
use App\Http\Requests\Api\App\V1\Maintenance\StoreDailyPropertyExpenseRequest;
use App\Http\Requests\Api\App\V1\Maintenance\UpdateDailyPropertyExpenseRequest;
use App\Http\Resources\App\V1\Maintenance\DailyPropertyExpenseResource;
use App\Models\Tenant\DailyExpenseType;
use App\Models\Tenant\DailyPropertyExpense;
use App\Models\Tenant\Property;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenancy\Tenant;
use App\Services\V1\Billing\PropertySubscriptionAccessService;
use App\Services\V1\PropertyAssignmentAccessService;
use App\Services\V1\SubscriptionService;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DailyPropertyExpenseController extends Controller
{
    use InteractsWithTenantModels;

    public function __construct(
        private PropertyAssignmentAccessService $propertyAssignmentAccessService,
        private PropertySubscriptionAccessService $propertySubscriptionAccessService,
        private SubscriptionService $subscriptionService,
    ) {
    }

    public function index(DailyPropertyExpenseIndexRequest $request)
    {
        $this->authorize('viewAny', DailyPropertyExpense::class);

        $filters = $request->validated();
        $tenantUser = request()->user();
        $query = DailyPropertyExpense::query()
            ->with([
                'property:id,uuid,name,currency,status',
                'expenseType:id,uuid,name',
                'recordedBy:id,uuid,name,email',
            ]);

        if ($tenantUser instanceof TenantUser) {
            $this->propertyAssignmentAccessService->scopeDailyPropertyExpenses($query, $tenantUser);
        }

        if (!empty($filters['property_uuid'] ?? null)) {
            $property = $this->resolveModelByUuid(Property::class, $filters['property_uuid']);
            if (!$property) {
                return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
            }

            $query->where('property_id', $property->id);
        }

        if (!empty($filters['expense_type_uuid'] ?? null)) {
            $expenseType = $this->resolveModelByUuid(DailyExpenseType::class, $filters['expense_type_uuid']);
            if (!$expenseType) {
                return ApiResponse::error('Daily expense type not found', ['expense_type_uuid' => ['Invalid daily expense type identifier']], 422);
            }

            $query->where('expense_type_id', $expenseType->id);
        }

        if (!empty($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $innerQuery) use ($search) {
                $innerQuery
                    ->where('title', 'like', $search.'%')
                    ->orWhereHas('expenseType', fn (Builder $typeQuery) => $typeQuery->where('name', 'like', $search.'%'))
                    ->orWhereHas('property', fn (Builder $propertyQuery) => $propertyQuery->where('name', 'like', $search.'%'));
            });
        }

        if (!empty($filters['start_date'] ?? null) || !empty($filters['end_date'] ?? null)) {
            $startDate = $filters['start_date'] ?? $filters['end_date'];
            $endDate = $filters['end_date'] ?? $filters['start_date'];
            $query->whereBetween('expense_date', [$startDate, $endDate]);
        }

        $this->applyIndexSort($query, $filters['sort'] ?? null);
        $expenses = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(DailyPropertyExpenseResource::collection($expenses), ApiMessages::listRetrieved('daily property expenses'));
    }

    public function store(StoreDailyPropertyExpenseRequest $request)
    {
        $this->authorize('create', DailyPropertyExpense::class);
        $this->assertWorkspaceAllowsInventoryMutation();

        $data = $request->validated();
        $property = $this->resolvePropertyForMutation((string) $data['property_uuid']);
        if ($property instanceof JsonResponse) {
            return $property;
        }
        $expenseType = $this->resolveExpenseType((string) $data['expense_type_uuid']);
        if ($expenseType instanceof JsonResponse) {
            return $expenseType;
        }

        $expense = DB::transaction(function () use ($data, $property, $expenseType) {
            return DailyPropertyExpense::query()->create([
                'property_id' => $property->id,
                'expense_type_id' => $expenseType->id,
                'title' => $expenseType->name,
                'description' => $this->normalizeDescription($data['description'] ?? null),
                'amount' => $data['amount'],
                'currency' => $this->resolveExpenseCurrency($property),
                'expense_date' => $data['expense_date'] ?? now()->toDateString(),
                'recorded_by' => request()->user() instanceof TenantUser ? request()->user()->id : null,
            ]);
        });

        return ApiResponse::resource(
            new DailyPropertyExpenseResource($this->reloadDailyPropertyExpense($expense)),
            ApiMessages::created('daily property expense'),
            201
        );
    }

    public function show(DailyPropertyExpense $dailyPropertyExpense)
    {
        $this->authorize('view', $dailyPropertyExpense);

        return ApiResponse::resource(
            new DailyPropertyExpenseResource($this->reloadDailyPropertyExpense($dailyPropertyExpense)),
            ApiMessages::detailsRetrieved('daily property expense')
        );
    }

    public function update(UpdateDailyPropertyExpenseRequest $request, DailyPropertyExpense $dailyPropertyExpense)
    {
        $this->authorize('update', $dailyPropertyExpense);
        $this->assertWorkspaceAllowsInventoryMutation();

        $data = $request->validated();
        $property = array_key_exists('property_uuid', $data)
            ? $this->resolvePropertyForMutation((string) $data['property_uuid'])
            : $dailyPropertyExpense->property;
        $expenseType = array_key_exists('expense_type_uuid', $data)
            ? $this->resolveExpenseType((string) $data['expense_type_uuid'])
            : $dailyPropertyExpense->expenseType;

        if ($property instanceof JsonResponse) {
            return $property;
        }
        if ($expenseType instanceof JsonResponse) {
            return $expenseType;
        }

        DB::transaction(function () use ($dailyPropertyExpense, $property, $expenseType, $data) {
            $dailyPropertyExpense->fill([
                'property_id' => $property->id,
                'expense_type_id' => $expenseType?->id,
                'title' => $expenseType?->name ?? $dailyPropertyExpense->title,
                'description' => array_key_exists('description', $data) ? $this->normalizeDescription($data['description']) : $dailyPropertyExpense->description,
                'amount' => $data['amount'] ?? $dailyPropertyExpense->amount,
                'currency' => $this->resolveExpenseCurrency($property),
                'expense_date' => $data['expense_date'] ?? $dailyPropertyExpense->expense_date,
            ])->save();
        });

        return ApiResponse::resource(
            new DailyPropertyExpenseResource($this->reloadDailyPropertyExpense($dailyPropertyExpense)),
            ApiMessages::updated('daily property expense')
        );
    }

    public function destroy(DailyPropertyExpense $dailyPropertyExpense)
    {
        $this->authorize('delete', $dailyPropertyExpense);
        $this->assertWorkspaceAllowsInventoryMutation();

        $property = $dailyPropertyExpense->loadMissing('property')->property;
        if ($property && ($error = $this->assertPropertyAllowsMaintenance($property))) {
            return $error;
        }

        DB::transaction(fn () => $dailyPropertyExpense->delete());

        return ApiResponse::success(ApiMessages::deleted('daily property expense'));
    }

    private function resolvePropertyForMutation(string $propertyUuid): Property|JsonResponse
    {
        $property = Property::query()
            ->select(['id', 'uuid', 'name', 'currency', 'status'])
            ->where('uuid', $propertyUuid)
            ->first();

        if (!$property) {
            return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
        }

        $tenantUser = request()->user();
        if ($tenantUser instanceof TenantUser
            && !$this->propertyAssignmentAccessService->canAccessPropertyModel($tenantUser, $property)) {
            return ApiResponse::forbidden(['property' => ['You do not have access to the selected property.']]);
        }

        if ($error = $this->assertPropertyAllowsMaintenance($property)) {
            return $error;
        }

        return $property;
    }

    private function reloadDailyPropertyExpense(DailyPropertyExpense $dailyPropertyExpense): DailyPropertyExpense
    {
        return DailyPropertyExpense::query()
            ->with([
                'property:id,uuid,name,currency,status',
                'expenseType:id,uuid,name',
                'recordedBy:id,uuid,name,email',
            ])
            ->findOrFail($dailyPropertyExpense->id);
    }

    private function resolveExpenseType(string $expenseTypeUuid): DailyExpenseType|JsonResponse
    {
        $expenseType = DailyExpenseType::query()
            ->select(['id', 'uuid', 'name'])
            ->where('uuid', $expenseTypeUuid)
            ->first();

        if (!$expenseType) {
            return ApiResponse::error('Daily expense type not found', ['expense_type_uuid' => ['Invalid daily expense type identifier']], 422);
        }

        return $expenseType;
    }

    private function normalizeDescription(?string $value): ?string
    {
        $normalized = Str::of((string) $value)->trim()->squish()->toString();

        return $normalized !== '' ? $normalized : null;
    }

    private function resolveExpenseCurrency(Property $property): string
    {
        $currency = strtoupper(trim((string) ($property->currency ?? '')));

        return $currency !== '' ? $currency : 'TZS';
    }

    private function applyIndexSort(Builder $query, ?string $sort): void
    {
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $column = ltrim((string) $sort, '-');

        match ($column) {
            'amount' => $query->orderBy('amount', $direction)->orderBy('expense_date', 'desc')->orderBy('id', 'desc'),
            'title' => $query->orderBy('title', $direction)->orderBy('expense_date', 'desc')->orderBy('id', 'desc'),
            'created_at' => $query->orderBy('created_at', $direction)->orderBy('id', 'desc'),
            'expense_date', '' => $query->orderBy('expense_date', $direction)->orderBy('id', 'desc'),
            default => $query->orderBy('expense_date', 'desc')->orderBy('id', 'desc'),
        };
    }

    private function assertWorkspaceAllowsInventoryMutation(): void
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            $this->subscriptionService->assertWorkspaceAllowsPropertyScopedMutation($tenant);
        }
    }

    private function assertPropertyAllowsMaintenance(Property $property): ?\Illuminate\Http\JsonResponse
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            try {
                $this->propertySubscriptionAccessService->assertPropertyAllowsOperationalMutation($tenant, $property, 'maintenance');
            } catch (InvalidArgumentException $exception) {
                return ApiResponse::error(
                    'This property is not paid for right now. Renew or activate the property subscription to continue.',
                    ['property_subscription' => [$exception->getMessage()]],
                    422
                );
            }
        }

        return null;
    }
}
