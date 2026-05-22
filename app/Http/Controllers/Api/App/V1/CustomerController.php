<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\CustomerIndexRequest;
use App\Http\Requests\Api\App\V1\StoreCustomerRequest;
use App\Http\Requests\Api\App\V1\UpdateCustomerRequest;
use App\Http\Resources\App\V1\CustomerResource;
use App\Models\Tenant\Customer;
use App\Services\V1\Occupancy\CustomerContractRuleService;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    use InteractsWithTenantModels;

    public function __construct(private CustomerContractRuleService $ruleService)
    {
    }

    public function index(CustomerIndexRequest $request)
    {
        $this->authorize('viewAny', Customer::class);

        $filters = $request->validated();
        $query = Customer::query()->withCount('contracts');

        if (!empty($filters['unit_uuid'] ?? null)) {
            $unit = $this->resolveModelByUuid(\App\Models\Tenant\Unit::class, $filters['unit_uuid']);
            if (!$unit) {
                return ApiResponse::error('Unit not found', ['unit_uuid' => ['Invalid unit identifier']], 422);
            }

            $query->whereHas('contracts', fn ($innerQuery) => $innerQuery->where('unit_id', $unit->id));
        }

        if (!empty($filters['property_uuid'] ?? null)) {
            $property = $this->resolveModelByUuid(\App\Models\Tenant\Property::class, $filters['property_uuid']);
            if (!$property) {
                return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
            }

            $query->whereHas('contracts.unit.propertyFloor', fn ($innerQuery) => $innerQuery->where('property_id', $property->id));
        }

        if (!empty($filters['customer_type'] ?? null)) {
            $query->where('customer_type', $filters['customer_type']);
        }

        if (!empty($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['display_name'] ?? null)) {
            $query->where('display_name', 'like', $filters['display_name'].'%');
        }

        $this->applySort($query, $filters['sort'] ?? null, ['display_name', 'created_at'], 'display_name', 'asc');
        $customers = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(CustomerResource::collection($customers), ApiMessages::listRetrieved('customers'));
    }

    public function store(StoreCustomerRequest $request)
    {
        $this->authorize('create', Customer::class);

        $data = $request->validated();
        $duplicateCustomer = $this->ruleService->findDuplicateCustomer($data);
        if ($duplicateCustomer) {
            return ApiResponse::error('Customer already exists', ['customer' => ['Duplicate customer record in this workspace']], 422);
        }

        $customer = DB::transaction(function () use ($data) {
            $customer = Customer::query()->create([
                'customer_type' => $data['customer_type'],
                'display_name' => $this->normalizeDisplayName($data['display_name']) ?? '',
                'email' => $this->normalizeEmail($data['email'] ?? null),
                'phone' => $this->normalizePhone($data['phone'] ?? null),
                'status' => $data['status'] ?? 'active',
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncBusinessDetail($customer, $data['business_details'] ?? null);

            return $customer;
        });

        return ApiResponse::resource(
            new CustomerResource($customer->load(['businessDetail'])->loadCount('contracts')),
            ApiMessages::created('customer'),
            201
        );
    }

    public function show(Customer $customer)
    {
        $this->authorize('view', $customer);

        return ApiResponse::resource(
            new CustomerResource($customer->load(['businessDetail'])->loadCount('contracts')),
            ApiMessages::detailsRetrieved('customer')
        );
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $data = $request->validated();
        $duplicateCustomer = $this->ruleService->findDuplicateCustomer(array_merge([
            'customer_type' => $data['customer_type'] ?? $customer->customer_type,
            'display_name' => $data['display_name'] ?? $customer->display_name,
            'email' => array_key_exists('email', $data) ? $data['email'] : $customer->email,
            'phone' => array_key_exists('phone', $data) ? $data['phone'] : $customer->phone,
            'business_details' => $data['business_details'] ?? [
                'business_name' => $customer->businessDetail?->business_name,
                'registration_number' => $customer->businessDetail?->registration_number,
                'tax_identifier' => $customer->businessDetail?->tax_identifier,
            ],
        ], $data), $customer->id);
        if ($duplicateCustomer) {
            return ApiResponse::error('Customer already exists', ['customer' => ['Duplicate customer record in this workspace']], 422);
        }

        DB::transaction(function () use ($customer, $data) {
            $customer->fill([
                'customer_type' => $data['customer_type'] ?? $customer->customer_type,
                'display_name' => isset($data['display_name']) ? ($this->normalizeDisplayName($data['display_name']) ?? $customer->display_name) : $customer->display_name,
                'email' => array_key_exists('email', $data) ? $this->normalizeEmail($data['email']) : $customer->email,
                'phone' => array_key_exists('phone', $data) ? $this->normalizePhone($data['phone']) : $customer->phone,
                'status' => $data['status'] ?? $customer->status,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $customer->notes,
            ])->save();

            if (array_key_exists('business_details', $data) || array_key_exists('customer_type', $data)) {
                $this->syncBusinessDetail($customer, $data['business_details'] ?? null);
            }
        });

        return ApiResponse::resource(
            new CustomerResource($customer->fresh()->load(['businessDetail'])->loadCount('contracts')),
            ApiMessages::updated('customer')
        );
    }

    public function destroy(Customer $customer)
    {
        $this->authorize('delete', $customer);

        DB::transaction(fn () => $customer->delete());

        return ApiResponse::success(ApiMessages::deleted('customer'));
    }

    private function syncBusinessDetail(Customer $customer, ?array $businessDetail): void
    {
        if ($customer->customer_type !== 'business') {
            $customer->businessDetail()?->delete();

            return;
        }

        $payload = $businessDetail ?? [];

        $customer->businessDetail()->updateOrCreate([], [
            'business_name' => $this->normalizeDisplayName($payload['business_name'] ?? '') ?? '',
            'registration_number' => $this->normalizeBusinessCode($payload['registration_number'] ?? null),
            'tax_identifier' => $this->normalizeBusinessCode($payload['tax_identifier'] ?? null),
            'contact_person_name' => $this->normalizeDisplayName($payload['contact_person_name'] ?? null),
            'contact_person_phone' => $this->normalizePhone($payload['contact_person_phone'] ?? null),
            'address_line' => $payload['address_line'] ?? null,
        ]);
    }

    private function normalizeDisplayName(?string $value): ?string
    {
        $value = Str::of((string) $value)->trim()->squish()->toString();

        return $value !== '' ? $value : null;
    }

    private function normalizeEmail(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? Str::lower($value) : null;
    }

    private function normalizePhone(?string $value): ?string
    {
        $value = preg_replace('/[^0-9+]/', '', (string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeBusinessCode(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? Str::upper($value) : null;
    }
}
