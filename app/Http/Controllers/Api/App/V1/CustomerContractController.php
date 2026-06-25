<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\CustomerContractIndexRequest;
use App\Http\Requests\Api\App\V1\CustomerContractNextNumberRequest;
use App\Http\Requests\Api\App\V1\StoreCustomerContractRequest;
use App\Http\Requests\Api\App\V1\UpdateCustomerContractRequest;
use App\Http\Resources\App\V1\CustomerContractResource;
use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerContract;
use App\Models\Tenant\Property;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenancy\Tenant;
use App\Services\V1\Billing\PropertySubscriptionAccessService;
use App\Services\V1\Occupancy\CustomerContractRuleService;
use App\Services\V1\PropertyAssignmentAccessService;
use App\Services\V1\SubscriptionService;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CustomerContractController extends Controller
{
    use InteractsWithTenantModels;

    /**
     * Create a new instance.
     */
    public function __construct(
        private CustomerContractRuleService $ruleService,
        private PropertyAssignmentAccessService $propertyAssignmentAccessService,
        private PropertySubscriptionAccessService $propertySubscriptionAccessService,
        private SubscriptionService $subscriptionService,
    ) {
    }

    /**
     * Handle the index request.
     */
    public function index(CustomerContractIndexRequest $request)
    {
        $this->authorize('viewAny', CustomerContract::class);

        $filters = $request->validated();
        $tenantUser = request()->user();
        $query = CustomerContract::query()
            ->with(['customer.property', 'unit.propertyFloor.property'])
            ->withCount('documents');

        if ($tenantUser instanceof TenantUser) {
            $this->propertyAssignmentAccessService->scopeContracts($query, $tenantUser);
        }

        if (!empty($filters['property_uuid'] ?? null)) {
            $property = $this->resolveModelByUuid(Property::class, $filters['property_uuid']);
            if (!$property) {
                return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
            }
            $query->whereHas('unit.propertyFloor', fn ($q) => $q->where('property_id', $property->id));
        }

        if (!empty($filters['customer_uuid'] ?? null)) {
            $customer = $this->resolveModelByUuid(Customer::class, $filters['customer_uuid']);
            if (!$customer) {
                return ApiResponse::error('Customer not found', ['customer_uuid' => ['Invalid customer identifier']], 422);
            }

            $query->where('customer_id', $customer->id);
        }

        if (!empty($filters['unit_uuid'] ?? null)) {
            $unit = $this->resolveModelByUuid(Unit::class, $filters['unit_uuid']);
            if (!$unit) {
                return ApiResponse::error('Unit not found', ['unit_uuid' => ['Invalid unit identifier']], 422);
            }

            $query->where('unit_id', $unit->id);
        }

        if (!empty($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'] ?? null)) {
            $query->where(function ($innerQuery) use ($filters) {
                $innerQuery
                    ->where('contract_number', 'like', $filters['search'] . '%')
                    ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('display_name', 'like', $filters['search'] . '%'));
            });
        }

        if (!empty($filters['contract_number'] ?? null)) {
            $query->where('contract_number', 'like', $filters['contract_number'] . '%');
        }

        if (!empty($filters['start_date'] ?? null) || !empty($filters['end_date'] ?? null)) {
            $startDate = $filters['start_date'] ?? $filters['end_date'];
            $endDate = $filters['end_date'] ?? $filters['start_date'];

            $query->where('start_date', '<=', $endDate)
                ->where(function ($innerQuery) use ($startDate) {
                    $innerQuery
                        ->whereNull('end_date')
                        ->orWhere('end_date', '>=', $startDate);
                });
        }

        $this->applySort($query, $filters['sort'] ?? null, ['start_date', 'contract_number', 'created_at'], 'start_date', 'desc');
        $contracts = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(CustomerContractResource::collection($contracts), ApiMessages::listRetrieved('customer contracts'));
    }

    /**
     * Handle next contract number request.
     */
    public function nextNumber(CustomerContractNextNumberRequest $request)
    {
        $this->authorize('create', CustomerContract::class);

        $data = $request->validated();
        $unit = $this->resolveModelByUuid(Unit::class, $data['unit_uuid']);

        if (!$unit) {
            return ApiResponse::error('Unit not found', ['unit_uuid' => ['Invalid unit identifier']], 422);
        }

        $unit->loadMissing('propertyFloor.property');

        if (!$unit->propertyFloor || !$unit->propertyFloor->property) {
            return ApiResponse::error(
                'Unit property not found',
                ['unit_uuid' => ['The selected unit is not attached to a valid property.']],
                422
            );
        }

        if ($response = $this->ensureUserCanAccessProperty($unit->propertyFloor->property)) {
            return $response;
        }

        $startDate = (string) ($data['start_date'] ?? Carbon::today()->toDateString());

        return ApiResponse::success('Next contract number generated successfully.', [
            'next_number' => $this->generateContractNumber(
                (int) $unit->propertyFloor->property->id,
                $startDate
            ),
            'unit_uuid' => $unit->uuid,
            'property_uuid' => $unit->propertyFloor->property->uuid,
            'start_date' => $startDate,
        ]);
    }

    /**
     * Handle the store request.
     */
    public function store(StoreCustomerContractRequest $request)
    {
        $this->authorize('create', CustomerContract::class);
        $this->assertWorkspaceAllowsInventoryMutation();

        $data = $request->validated();
        [$customer, $unitId, $error] = $this->resolveCustomerAndUnit($data);
        if (!$customer) {
            return ApiResponse::error('Customer not found', ['customer_uuid' => ['Invalid customer identifier']], 422);
        }
        if ($error !== null) {
            return $error;
        }

        $unit = Unit::query()->with('propertyFloor.property')->find($unitId);
        if (!$unit || !$unit->propertyFloor || !$unit->propertyFloor->property) {
            return ApiResponse::error('Unit property not found', ['unit_uuid' => ['The selected unit is not attached to a valid property.']], 422);
        }

        if ($response = $this->ensureUserCanAccessProperty($unit->propertyFloor->property)) {
            return $response;
        }

        if ($response = $this->assertCustomerBelongsToUnitProperty($customer, $unit)) {
            return $response;
        }

        if ($error = $this->assertPropertyAllowsContractOperations($unit->propertyFloor->property, $data['start_date'])) {
            return $error;
        }

        if ($this->ruleService->hasOverlappingUnitContract($unitId, $data['start_date'], $data['end_date'] ?? null)) {
            return ApiResponse::error('Contract period conflict', ['unit_uuid' => ['This unit already has an overlapping contract period']], 422);
        }

        $contract = DB::transaction(function () use ($customer, $unitId, $data, $unit) {
            $contract = CustomerContract::query()->create([
                'customer_id' => $customer->id,
                'unit_id' => $unitId,
                'contract_number' => $this->generateContractNumber(
                    (int) $unit->propertyFloor->property->id,
                    (string) $data['start_date']
                ),
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'amount' => $data['amount'],
                'currency' => strtoupper($data['currency'] ?? 'TZS'),
                'status' => $data['status'] ?? 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            $this->ruleService->syncUnitOccupancyStatus($unitId);
            $this->ruleService->syncCustomerStatuses([$customer->id]);

            return $contract;
        });

        return ApiResponse::resource(
            new CustomerContractResource($contract->load(['customer.property', 'unit.propertyFloor.property', 'documents'])->loadCount('documents')),
            ApiMessages::created('customer contract'),
            201
        );
    }

    /**
     * Handle the show request.
     */
    public function show(CustomerContract $customerContract)
    {
        $this->authorize('view', $customerContract);

        return ApiResponse::resource(
            new CustomerContractResource($customerContract->load(['customer.property', 'unit.propertyFloor.property', 'documents'])->loadCount('documents')),
            ApiMessages::detailsRetrieved('customer contract')
        );
    }

    /**
     * Handle the update request.
     */
    public function update(UpdateCustomerContractRequest $request, CustomerContract $customerContract)
    {
        $this->authorize('update', $customerContract);
        $this->assertWorkspaceAllowsInventoryMutation();

        $data = $request->validated();
        $customer = $customerContract->customer;
        $unitId = $customerContract->unit_id;

        if (!empty($data['customer_uuid'] ?? null) || !empty($data['unit_uuid'] ?? null)) {
            [$customer, $unitId, $error] = $this->resolveCustomerAndUnit($data, $customerContract->customer, $customerContract->unit_id);
            if (!$customer) {
                return ApiResponse::error('Customer not found', ['customer_uuid' => ['Invalid customer identifier']], 422);
            }
            if ($error !== null) {
                return $error;
            }
        }

        $unit = Unit::query()->with('propertyFloor.property')->find($unitId);
        if (!$unit || !$unit->propertyFloor || !$unit->propertyFloor->property) {
            return ApiResponse::error('Unit property not found', ['unit_uuid' => ['The selected unit is not attached to a valid property.']], 422);
        }

        if ($response = $this->ensureUserCanAccessProperty($unit->propertyFloor->property)) {
            return $response;
        }

        if ($response = $this->assertCustomerBelongsToUnitProperty($customer, $unit)) {
            return $response;
        }

        if (isset($data['contract_number'])) {
            $exists = CustomerContract::query()
                ->where('contract_number', trim($data['contract_number']))
                ->whereKeyNot($customerContract->id)
                ->exists();

            if ($exists) {
                return ApiResponse::error('Contract already exists', ['contract_number' => ['Duplicate contract number']], 422);
            }
        }

        $startDate = $data['start_date'] ?? $customerContract->start_date?->toDateString();
        $endDate = array_key_exists('end_date', $data)
            ? $data['end_date']
            : $customerContract->end_date?->toDateString();

        if ($error = $this->assertPropertyAllowsContractOperations($unit->propertyFloor->property, $startDate)) {
            return $error;
        }

        if ($this->ruleService->hasOverlappingUnitContract($unitId, $startDate, $endDate, $customerContract->id)) {
            return ApiResponse::error('Contract period conflict', ['unit_uuid' => ['This unit already has an overlapping contract period']], 422);
        }

        DB::transaction(function () use ($customerContract, $customer, $unitId, $data) {
            $previousUnitId = $customerContract->unit_id;
            $previousCustomerId = $customerContract->customer_id;

            $customerContract->fill([
                'customer_id' => $customer->id,
                'unit_id' => $unitId,
                'contract_number' => isset($data['contract_number']) ? trim($data['contract_number']) : $customerContract->contract_number,
                'start_date' => $data['start_date'] ?? $customerContract->start_date,
                'end_date' => array_key_exists('end_date', $data) ? $data['end_date'] : $customerContract->end_date,
                'amount' => $data['amount'] ?? $customerContract->amount,
                'currency' => isset($data['currency']) ? strtoupper($data['currency']) : $customerContract->currency,
                'status' => $data['status'] ?? $customerContract->status,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $customerContract->notes,
            ])->save();

            $this->ruleService->syncUnitOccupancyStatus($unitId);
            $this->ruleService->syncCustomerStatuses([$customer->id, $previousCustomerId]);

            if ($previousUnitId !== $unitId) {
                $this->ruleService->syncUnitOccupancyStatus($previousUnitId);
            }
        });

        return ApiResponse::resource(
            new CustomerContractResource($customerContract->fresh()->load(['customer.property', 'unit.propertyFloor.property', 'documents'])->loadCount('documents')),
            ApiMessages::updated('customer contract')
        );
    }

    /**
     * Handle the destroy request.
     */
    public function destroy(CustomerContract $customerContract)
    {
        $this->authorize('delete', $customerContract);
        $this->assertWorkspaceAllowsInventoryMutation();
        $property = $customerContract->loadMissing('unit.propertyFloor.property')->unit?->propertyFloor?->property;

        if (!$property) {
            return ApiResponse::error('Contract property not found', ['customer_contract' => ['The selected contract is not attached to a valid property.']], 422);
        }

        if ($response = $this->ensureUserCanAccessProperty($property)) {
            return $response;
        }

        if ($error = $this->assertPropertyAllowsContractOperations($property)) {
            return $error;
        }

        DB::transaction(function () use ($customerContract) {
            $unitId = $customerContract->unit_id;
            $customerId = $customerContract->customer_id;
            $customerContract->delete();
            $this->ruleService->syncUnitOccupancyStatus($unitId);
            $this->ruleService->syncCustomerStatuses([$customerId]);
        });

        return ApiResponse::success(ApiMessages::deleted('customer contract'));
    }

    /**
     * Resolve customer and unit.
     */
    private function resolveCustomerAndUnit(array $data, ?Customer $fallbackCustomer = null, ?int $fallbackUnitId = null): array
    {
        $customer = $fallbackCustomer;
        if (!empty($data['customer_uuid'] ?? null)) {
            $customer = $this->resolveModelByUuid(Customer::class, $data['customer_uuid']);
        }

        if (!$customer) {
            return [null, null, null];
        }

        $unitId = $fallbackUnitId;
        if (!empty($data['unit_uuid'] ?? null)) {
            $unit = $this->resolveModelByUuid(Unit::class, $data['unit_uuid']);
            if (!$unit) {
                return [$customer, null, ApiResponse::error('Unit not found', ['unit_uuid' => ['Invalid unit identifier']], 422)];
            }

            $unitId = $unit->id;
        } elseif ($unitId === null) {
            return [$customer, null, ApiResponse::error('Unit is required', ['unit_uuid' => ['Provide a valid unit identifier']], 422)];
        }

        return [$customer, $unitId, null];
    }

    /**
     * Ensure user can access property.
     */
    private function ensureUserCanAccessProperty(Property $property): ?\Illuminate\Http\JsonResponse
    {
        $tenantUser = request()->user();

        if ($tenantUser instanceof TenantUser
            && !$this->propertyAssignmentAccessService->userCanAccessProperty($tenantUser, (int) $property->id)) {
            return ApiResponse::forbidden(['property' => ['You do not have access to the selected property.']]);
        }

        return null;
    }

    /**
     * Assert customer belongs to unit property.
     */
    private function assertCustomerBelongsToUnitProperty(Customer $customer, Unit $unit): ?\Illuminate\Http\JsonResponse
    {
        $propertyId = $unit->propertyFloor?->property_id;

        if ($propertyId === null || (int) $customer->property_id !== (int) $propertyId) {
            return ApiResponse::error(
                'Customer property mismatch',
                ['customer_uuid' => ['The selected customer does not belong to the same property as the selected unit.']],
                422
            );
        }

        return null;
    }

    /**
     * Assert workspace allows inventory mutation.
     */
    private function assertWorkspaceAllowsInventoryMutation(): void
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            $this->subscriptionService->assertWorkspaceAllowsPropertyScopedMutation($tenant);
        }
    }

    /**
     * Assert property allows contract operations.
     */
    private function assertPropertyAllowsContractOperations(Property $property, ?string $startDate = null): ?\Illuminate\Http\JsonResponse
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            try {
                $this->propertySubscriptionAccessService->assertPropertyAllowsOperationalMutation($tenant, $property, 'contracts');
                if ($startDate !== null) {
                    $this->propertySubscriptionAccessService->assertContractStartDateCovered($tenant, $property, $startDate);
                }
            } catch (InvalidArgumentException $exception) {
                return ApiResponse::error(
                    'The selected contract start date is not paid for. Choose a start date that falls within the workspace trial period or the property subscription paid period.',
                    ['property_subscription' => [$exception->getMessage()]],
                    422
                );
            }
        }

        return null;
    }

    /**
     * Generate contract number.
     */
    private function generateContractNumber(int $propertyId, string $startDate): string
    {
        $year = Carbon::parse($startDate)->format('Y');
        $prefix = sprintf('CNT-%s-%d-', $year, $propertyId);

        $latestNumber = CustomerContract::query()
            ->where('contract_number', 'like', $prefix.'%')
            ->orderByDesc('contract_number')
            ->value('contract_number');

        $nextSequence = 1;

        if (is_string($latestNumber)
            && preg_match('/^CNT-\d{4}-\d+-(\d+)$/', $latestNumber, $matches) === 1) {
            $nextSequence = ((int) $matches[1]) + 1;
        }

        return sprintf('CNT-%s-%d-%d', $year, $propertyId, $nextSequence);
    }
}
