<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\CustomerContractIndexRequest;
use App\Http\Requests\Api\App\V1\CustomerContractNextNumberRequest;
use App\Http\Requests\Api\App\V1\StoreCustomerContractPaymentRequest;
use App\Http\Requests\Api\App\V1\StoreCustomerContractRequest;
use App\Http\Requests\Api\App\V1\UpdateCustomerContractRequest;
use App\Http\Resources\App\V1\CustomerContractResource;
use App\Http\Resources\App\V1\CustomerContractPaymentResource;
use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerContract;
use App\Models\Tenant\Property;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenancy\Tenant;
use App\Services\V1\Billing\PropertySubscriptionAccessService;
use App\Services\V1\Occupancy\CustomerContractFinanceService;
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
        private CustomerContractFinanceService $financeService,
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

        if (!empty($filters['payment_status'] ?? null)) {
            $query->where('payment_status', $filters['payment_status']);
        }

        if (!empty($filters['search'] ?? null)) {
            $query->where(function ($innerQuery) use ($filters) {
                $innerQuery
                    ->where('contract_number', 'like', $filters['search'].'%')
                    ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('display_name', 'like', $filters['search'].'%'));
            });
        }

        if (!empty($filters['contract_number'] ?? null)) {
            $query->where('contract_number', 'like', $filters['contract_number'].'%');
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
            'monthly_rent_amount' => (float) $unit->monthly_rent_amount,
            'rent_currency' => $unit->rent_currency ?? 'TZS',
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

        if ((float) ($unit->monthly_rent_amount ?? 0) <= 0) {
            return ApiResponse::error(
                'Unit price not configured',
                ['unit_uuid' => ['Set the unit monthly price before creating a contract for this unit.']],
                422
            );
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

        $computedEndDate = $this->financeService->calculateEndDate((string) $data['start_date'], (int) $data['contract_months']);
        $expectedTotal = $this->financeService->calculateExpectedTotal(
            (float) $unit->monthly_rent_amount,
            (int) $data['contract_months']
        );

        if ($error = $this->validateInitialPaymentAmount((float) ($data['initial_amount_paid'] ?? 0), $expectedTotal)) {
            return $error;
        }

        if ($this->ruleService->hasOverlappingUnitContract($unitId, $data['start_date'], $computedEndDate)) {
            return ApiResponse::error('Contract period conflict', ['unit_uuid' => ['This unit already has an overlapping contract period']], 422);
        }

        $contract = DB::transaction(function () use ($customer, $unitId, $data, $unit) {
            $contractMonths = (int) $data['contract_months'];
            $expectedTotal = $this->financeService->calculateExpectedTotal((float) $unit->monthly_rent_amount, $contractMonths);

            $contract = CustomerContract::query()->create([
                'customer_id' => $customer->id,
                'unit_id' => $unitId,
                'contract_number' => $this->generateContractNumber(
                    (int) $unit->propertyFloor->property->id,
                    (string) $data['start_date']
                ),
                'start_date' => $data['start_date'],
                'end_date' => $this->financeService->calculateEndDate((string) $data['start_date'], $contractMonths),
                'contract_months' => $contractMonths,
                'unit_price_at_contract' => (float) $unit->monthly_rent_amount,
                'amount' => $expectedTotal,
                'expected_total_amount' => $expectedTotal,
                'final_payable_amount' => $expectedTotal,
                'currency' => strtoupper($unit->rent_currency ?? 'TZS'),
                'status' => $data['status'] ?? 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            if ((float) ($data['initial_amount_paid'] ?? 0) > 0) {
                $this->financeService->recordPayment(
                    $contract,
                    (float) $data['initial_amount_paid'],
                    $data['payment_date'] ?? $data['start_date'],
                    'Initial contract payment.'
                );
            }

            $contract = $this->financeService->syncContractFinancials($contract);

            if ($contract->status === 'terminated' && $contract->termination_date) {
                $contract = $this->financeService->terminateContract(
                    $contract,
                    $contract->termination_date->toDateString(),
                    $contract->termination_reason
                );
            }

            $this->ruleService->syncUnitOccupancyStatus($unitId);
            $this->ruleService->syncCustomerStatuses([$customer->id]);

            return $contract;
        });

        return ApiResponse::resource(
            new CustomerContractResource($contract->load(['customer.property', 'unit.propertyFloor.property', 'documents', 'transactions'])->loadCount('documents')),
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
            new CustomerContractResource($customerContract->load(['customer.property', 'unit.propertyFloor.property', 'documents', 'transactions'])->loadCount('documents')),
            ApiMessages::detailsRetrieved('customer contract')
        );
    }

    /**
     * Record a contract payment.
     */
    public function recordPayment(StoreCustomerContractPaymentRequest $request, CustomerContract $customerContract)
    {
        $this->authorize('update', $customerContract);
        $this->assertWorkspaceAllowsInventoryMutation();

        $customerContract->loadMissing('unit.propertyFloor.property');
        $property = $customerContract->unit?->propertyFloor?->property;

        if (!$property) {
            return ApiResponse::error(
                'Contract property not found.',
                ['customer_contract' => ['The selected contract is not attached to a valid property.']],
                422
            );
        }

        if ($response = $this->ensureUserCanAccessProperty($property)) {
            return $response;
        }

        if ($customerContract->status === 'terminated') {
            return ApiResponse::error(
                'Cannot record payment for a terminated contract.',
                ['contract' => ['Terminated contracts cannot receive new payments.']],
                422
            );
        }

        if ($customerContract->payment_status === 'paid' || (float) $customerContract->outstanding_balance <= 0) {
            return ApiResponse::error(
                'Contract is already fully paid.',
                ['amount_paid' => ['There is no remaining balance to collect for this contract.']],
                422
            );
        }

        $data = $request->validated();

        if ($error = $this->validateAdditionalPaymentAmount(
            (float) $data['amount_paid'],
            $customerContract,
            (float) $customerContract->expected_total_amount
        )) {
            return $error;
        }

        DB::transaction(function () use ($customerContract, $data) {
            $this->financeService->recordPayment(
                $customerContract,
                (float) $data['amount_paid'],
                (string) $data['payment_date'],
                $data['notes'] ?? 'Contract payment recorded.'
            );

            $this->financeService->syncContractFinancials($customerContract);
        });

        return ApiResponse::success('Contract payment recorded successfully.', [
            'payment' => new CustomerContractPaymentResource(
                $customerContract->fresh()->load([
                    'transactions' => fn ($query) => $query->latest('transaction_date')->latest('id')->limit(1),
                ])
            ),
        ]);
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

        if ((float) ($unit->monthly_rent_amount ?? 0) <= 0) {
            return ApiResponse::error(
                'Unit price not configured',
                ['unit_uuid' => ['Set the unit monthly price before updating this contract.']],
                422
            );
        }

        if ($customerContract->transactions()->exists() && $this->changesPricingAnchorFields($data, $customerContract, $unitId)) {
            return ApiResponse::error(
                'Paid contract cannot be repriced.',
                ['contract' => ['This contract already has payment records, so unit, start date, and contract months cannot be changed. Create a new contract for the new unit, or terminate this one first if the tenant is moving.']],
                422
            );
        }

        if ($customerContract->status === 'terminated' && array_key_exists('status', $data) && $data['status'] !== 'terminated') {
            return ApiResponse::error(
                'Terminated contract cannot be reopened.',
                ['status' => ['Terminated contracts cannot be changed back to another lifecycle status.']],
                422
            );
        }

        if (($data['status'] ?? $customerContract->status) === 'terminated' && array_key_exists('additional_amount_paid', $data)) {
            return ApiResponse::error(
                'Payment and termination must be done separately.',
                ['additional_amount_paid' => ['You cannot record a payment while terminating the same contract. Record the payment first, or terminate the contract and let the system compute any refund automatically.']],
                422
            );
        }

        $startDate = (string) ($data['start_date'] ?? $customerContract->start_date?->toDateString());
        $contractMonths = (int) ($data['contract_months'] ?? $customerContract->contract_months);
        $endDate = $this->financeService->calculateEndDate($startDate, $contractMonths);
        $hasTransactions = $customerContract->transactions()->exists();
        $expectedTotal = $hasTransactions
            ? (float) $customerContract->expected_total_amount
            : $this->financeService->calculateExpectedTotal((float) $unit->monthly_rent_amount, $contractMonths);

        if ($error = $this->validateAdditionalPaymentAmount(
            (float) ($data['additional_amount_paid'] ?? 0),
            $customerContract,
            $expectedTotal
        )) {
            return $error;
        }

        if ($error = $this->assertPropertyAllowsContractOperations($unit->propertyFloor->property, $startDate)) {
            return $error;
        }

        if ($this->ruleService->hasOverlappingUnitContract($unitId, $startDate, $endDate, $customerContract->id)) {
            return ApiResponse::error('Contract period conflict', ['unit_uuid' => ['This unit already has an overlapping contract period']], 422);
        }

        DB::transaction(function () use ($customerContract, $customer, $unitId, $data, $unit) {
            $previousUnitId = $customerContract->unit_id;
            $previousCustomerId = $customerContract->customer_id;
            $hasTransactions = $customerContract->transactions()->exists();
            $resolvedContractMonths = (int) ($data['contract_months'] ?? $customerContract->contract_months);
            $resolvedStartDate = (string) ($data['start_date'] ?? $customerContract->start_date?->toDateString());
            $expectedTotal = $this->financeService->calculateExpectedTotal((float) $unit->monthly_rent_amount, $resolvedContractMonths);

            $customerContract->fill([
                'customer_id' => $customer->id,
                'unit_id' => $unitId,
                'contract_number' => isset($data['contract_number']) ? trim($data['contract_number']) : $customerContract->contract_number,
                'start_date' => $resolvedStartDate,
                'end_date' => $this->financeService->calculateEndDate($resolvedStartDate, $resolvedContractMonths),
                'contract_months' => $resolvedContractMonths,
                'unit_price_at_contract' => $hasTransactions ? $customerContract->unit_price_at_contract : (float) $unit->monthly_rent_amount,
                'amount' => $hasTransactions ? $customerContract->amount : $expectedTotal,
                'expected_total_amount' => $hasTransactions ? $customerContract->expected_total_amount : $expectedTotal,
                'final_payable_amount' => $hasTransactions ? $customerContract->final_payable_amount : $expectedTotal,
                'currency' => $hasTransactions ? $customerContract->currency : strtoupper($unit->rent_currency ?? $customerContract->currency),
                'status' => $data['status'] ?? $customerContract->status,
                'termination_date' => array_key_exists('termination_date', $data) ? $data['termination_date'] : $customerContract->termination_date,
                'termination_reason' => array_key_exists('termination_reason', $data) ? $data['termination_reason'] : $customerContract->termination_reason,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $customerContract->notes,
            ])->save();

            if ((float) ($data['additional_amount_paid'] ?? 0) > 0) {
                $this->financeService->recordPayment(
                    $customerContract,
                    (float) $data['additional_amount_paid'],
                    $data['payment_date'] ?? now()->toDateString(),
                    'Additional contract payment.'
                );
            }

            if (($data['status'] ?? $customerContract->status) === 'terminated' && !blank($customerContract->termination_date)) {
                $this->financeService->terminateContract(
                    $customerContract,
                    (string) $customerContract->termination_date,
                    $customerContract->termination_reason
                );
            } else {
                $this->financeService->syncContractFinancials($customerContract);
            }

            $this->ruleService->syncUnitOccupancyStatus($unitId);
            $this->ruleService->syncCustomerStatuses([$customer->id, $previousCustomerId]);

            if ($previousUnitId !== $unitId) {
                $this->ruleService->syncUnitOccupancyStatus($previousUnitId);
            }
        });

        return ApiResponse::resource(
            new CustomerContractResource($customerContract->fresh()->load(['customer.property', 'unit.propertyFloor.property', 'documents', 'transactions'])->loadCount('documents')),
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
                    'This unit may be available, but the selected contract start date is outside the workspace trial or property subscription paid period.',
                    ['property_subscription' => ['The unit is available for contracting, but the selected start date is not covered by the workspace trial or the property subscription paid period. Choose a covered start date or extend the property subscription first.']],
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

    private function changesPricingAnchorFields(array $data, CustomerContract $customerContract, int $resolvedUnitId): bool
    {
        if (array_key_exists('unit_uuid', $data) && $resolvedUnitId !== (int) $customerContract->unit_id) {
            return true;
        }

        if (array_key_exists('start_date', $data) && (string) $data['start_date'] !== $customerContract->start_date?->toDateString()) {
            return true;
        }

        return array_key_exists('contract_months', $data)
            && (int) $data['contract_months'] !== (int) $customerContract->contract_months;
    }

    private function validateInitialPaymentAmount(float $initialAmountPaid, float $expectedTotal): ?\Illuminate\Http\JsonResponse
    {
        if ($initialAmountPaid <= 0) {
            return null;
        }

        if ($initialAmountPaid - $expectedTotal > 0.00001) {
            return ApiResponse::error(
                'Initial payment is greater than the contract total.',
                ['initial_amount_paid' => ['Initial amount paid cannot exceed the computed contract total for this contract.']],
                422
            );
        }

        return null;
    }

    private function validateAdditionalPaymentAmount(
        float $additionalAmountPaid,
        CustomerContract $customerContract,
        float $expectedTotal
    ): ?\Illuminate\Http\JsonResponse {
        if ($additionalAmountPaid <= 0) {
            return null;
        }

        $remainingBeforePayment = $customerContract->transactions()->exists()
            ? (float) $customerContract->outstanding_balance
            : max($expectedTotal - (float) $customerContract->net_collected_amount, 0);

        if ($additionalAmountPaid - $remainingBeforePayment > 0.00001) {
            return ApiResponse::error(
                'Payment is greater than the remaining balance.',
                [
                    'additional_amount_paid' => ['Additional amount paid cannot exceed the remaining unpaid balance for this contract.'],
                    'amount_paid' => ['Payment amount cannot exceed the remaining unpaid balance for this contract.'],
                ],
                422
            );
        }

        return null;
    }
}
