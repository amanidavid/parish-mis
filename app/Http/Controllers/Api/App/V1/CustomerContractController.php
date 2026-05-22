<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\CustomerContractIndexRequest;
use App\Http\Requests\Api\App\V1\StoreCustomerContractRequest;
use App\Http\Requests\Api\App\V1\UpdateCustomerContractRequest;
use App\Http\Resources\App\V1\CustomerContractResource;
use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerContract;
use App\Models\Tenant\Unit;
use App\Services\V1\Occupancy\CustomerContractRuleService;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;

class CustomerContractController extends Controller
{
    use InteractsWithTenantModels;

    public function __construct(private CustomerContractRuleService $ruleService)
    {
    }

    public function index(CustomerContractIndexRequest $request)
    {
        $this->authorize('viewAny', CustomerContract::class);

        $filters = $request->validated();
        $query = CustomerContract::query()
            ->with(['customer', 'unit.propertyFloor.property'])
            ->withCount('documents');

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

        if (!empty($filters['contract_number'] ?? null)) {
            $query->where('contract_number', 'like', $filters['contract_number'].'%');
        }

        $this->applySort($query, $filters['sort'] ?? null, ['start_date', 'contract_number', 'created_at'], 'start_date', 'desc');
        $contracts = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(CustomerContractResource::collection($contracts), ApiMessages::listRetrieved('customer contracts'));
    }

    public function store(StoreCustomerContractRequest $request)
    {
        $this->authorize('create', CustomerContract::class);

        $data = $request->validated();
        [$customer, $unitId, $error] = $this->resolveCustomerAndUnit($data);
        if (!$customer) {
            return ApiResponse::error('Customer not found', ['customer_uuid' => ['Invalid customer identifier']], 422);
        }
        if ($error !== null) {
            return $error;
        }

        $exists = CustomerContract::query()->where('contract_number', trim($data['contract_number']))->exists();
        if ($exists) {
            return ApiResponse::error('Contract already exists', ['contract_number' => ['Duplicate contract number']], 422);
        }

        if ($this->ruleService->hasOverlappingUnitContract($unitId, $data['start_date'], $data['end_date'] ?? null)) {
            return ApiResponse::error('Contract period conflict', ['unit_uuid' => ['This unit already has an overlapping contract period']], 422);
        }

        $contract = DB::transaction(function () use ($customer, $unitId, $data) {
            $contract = CustomerContract::query()->create([
                'customer_id' => $customer->id,
                'unit_id' => $unitId,
                'contract_number' => trim($data['contract_number']),
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'amount' => $data['amount'],
                'currency' => strtoupper($data['currency'] ?? 'TZS'),
                'billing_cycle' => $data['billing_cycle'] ?? 'monthly',
                'status' => $data['status'] ?? 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            $this->ruleService->syncUnitOccupancyStatus($unitId);

            return $contract;
        });

        return ApiResponse::resource(
            new CustomerContractResource($contract->load(['customer', 'unit.propertyFloor.property', 'documents'])->loadCount('documents')),
            ApiMessages::created('customer contract'),
            201
        );
    }

    public function show(CustomerContract $customerContract)
    {
        $this->authorize('view', $customerContract);

        return ApiResponse::resource(
            new CustomerContractResource($customerContract->load(['customer', 'unit.propertyFloor.property', 'documents'])->loadCount('documents')),
            ApiMessages::detailsRetrieved('customer contract')
        );
    }

    public function update(UpdateCustomerContractRequest $request, CustomerContract $customerContract)
    {
        $this->authorize('update', $customerContract);

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

        if ($this->ruleService->hasOverlappingUnitContract($unitId, $startDate, $endDate, $customerContract->id)) {
            return ApiResponse::error('Contract period conflict', ['unit_uuid' => ['This unit already has an overlapping contract period']], 422);
        }

        DB::transaction(function () use ($customerContract, $customer, $unitId, $data) {
            $previousUnitId = $customerContract->unit_id;

            $customerContract->fill([
                'customer_id' => $customer->id,
                'unit_id' => $unitId,
                'contract_number' => isset($data['contract_number']) ? trim($data['contract_number']) : $customerContract->contract_number,
                'start_date' => $data['start_date'] ?? $customerContract->start_date,
                'end_date' => array_key_exists('end_date', $data) ? $data['end_date'] : $customerContract->end_date,
                'amount' => $data['amount'] ?? $customerContract->amount,
                'currency' => isset($data['currency']) ? strtoupper($data['currency']) : $customerContract->currency,
                'billing_cycle' => $data['billing_cycle'] ?? $customerContract->billing_cycle,
                'status' => $data['status'] ?? $customerContract->status,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $customerContract->notes,
            ])->save();

            $this->ruleService->syncUnitOccupancyStatus($unitId);

            if ($previousUnitId !== $unitId) {
                $this->ruleService->syncUnitOccupancyStatus($previousUnitId);
            }
        });

        return ApiResponse::resource(
            new CustomerContractResource($customerContract->fresh()->load(['customer', 'unit.propertyFloor.property', 'documents'])->loadCount('documents')),
            ApiMessages::updated('customer contract')
        );
    }

    public function destroy(CustomerContract $customerContract)
    {
        $this->authorize('delete', $customerContract);

        DB::transaction(function () use ($customerContract) {
            $unitId = $customerContract->unit_id;
            $customerContract->delete();
            $this->ruleService->syncUnitOccupancyStatus($unitId);
        });

        return ApiResponse::success(ApiMessages::deleted('customer contract'));
    }

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
}
