<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\CustomerIndexRequest;
use App\Http\Requests\Api\App\V1\StoreCustomerRequest;
use App\Http\Requests\Api\App\V1\UpdateCustomerRequest;
use App\Http\Resources\App\V1\CustomerResource;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Property;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User as TenantUser;
use App\Services\V1\Occupancy\CustomerContractRuleService;
use App\Services\V1\PropertyAssignmentAccessService;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    use InteractsWithTenantModels;

    /**
     * Create a new instance.
     */
    public function __construct(
        private CustomerContractRuleService $ruleService,
        private PropertyAssignmentAccessService $propertyAssignmentAccessService,
    ) {
    }

    /**
     * Handle the index request.
     */
    public function index(CustomerIndexRequest $request)
    {
        $this->authorize('viewAny', Customer::class);

        $filters = $request->validated();
        $tenantUser = request()->user();
        $query = Customer::query()
            ->with(['property'])
            ->withCount('contracts');

        if ($tenantUser instanceof TenantUser) {
            $this->propertyAssignmentAccessService->scopeCustomers($query, $tenantUser);
        }

        if (!empty($filters['unit_uuid'] ?? null)) {
            $unit = $this->resolveModelByUuid(Unit::class, $filters['unit_uuid']);
            if (!$unit) {
                return ApiResponse::error('Unit not found', ['unit_uuid' => ['Invalid unit identifier']], 422);
            }

            $query->whereHas('contracts', fn ($innerQuery) => $innerQuery->where('unit_id', $unit->id));
        }

        if (!empty($filters['property_uuid'] ?? null)) {
            $property = $this->resolveModelByUuid(Property::class, $filters['property_uuid']);
            if (!$property) {
                return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
            }

            $query->where('property_id', $property->id);
        }

        if (!empty($filters['customer_type'] ?? null)) {
            $query->where('customer_type', $filters['customer_type']);
        }

        if (!empty($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'] ?? null)) {
            $this->applyPrefixSearch($query, $filters['search'], ['display_name', 'email', 'phone']);
        }

        if (!empty($filters['display_name'] ?? null)) {
            $query->where('display_name', 'like', $filters['display_name'] . '%');
        }

        $this->applySort($query, $filters['sort'] ?? null, ['display_name', 'created_at'], 'display_name', 'asc');
        $customers = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(CustomerResource::collection($customers), ApiMessages::listRetrieved('customers'));
    }

    /**
     * Handle the store request.
     */
    public function store(StoreCustomerRequest $request)
    {
        $this->authorize('create', Customer::class);

        $data = $request->validated();
        $property = $this->resolveModelByUuid(Property::class, $data['property_uuid']);
        if (!$property) {
            return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
        }

        if ($response = $this->ensureUserCanAccessProperty($property)) {
            return $response;
        }

        $duplicateCustomer = $this->ruleService->findDuplicateCustomer($data, (int) $property->id);
        if ($duplicateCustomer) {
            return ApiResponse::error('Customer already exists', ['customer' => ['Duplicate customer record in the selected property']], 422);
        }

        $customer = DB::transaction(function () use ($data, $property) {
            $customer = Customer::query()->create([
                'property_id' => $property->id,
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
            new CustomerResource($customer->load(['property', 'businessDetail'])->loadCount('contracts')),
            ApiMessages::created('customer'),
            201
        );
    }

    /**
     * Handle the show request.
     */
    public function show(Customer $customer)
    {
        $this->authorize('view', $customer);

        return ApiResponse::resource(
            new CustomerResource($customer->load(['property', 'businessDetail'])->loadCount('contracts')),
            ApiMessages::detailsRetrieved('customer')
        );
    }

    /**
     * Handle the update request.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $data = $request->validated();
        $property = $customer->property;

        if (array_key_exists('property_uuid', $data)) {
            $property = $this->resolveModelByUuid(Property::class, $data['property_uuid']);
            if (!$property) {
                return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
            }

            if ($response = $this->ensureUserCanAccessProperty($property)) {
                return $response;
            }

            if ($customer->contracts()->exists() && (int) $property->id !== (int) $customer->property_id) {
                return ApiResponse::error(
                    'Customer property change is not allowed',
                    ['property_uuid' => ['Customers with existing contracts cannot be moved to another property.']],
                    422
                );
            }
        }

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
        ], $data), (int) ($property?->id ?? $customer->property_id), $customer->id);
        if ($duplicateCustomer) {
            return ApiResponse::error('Customer already exists', ['customer' => ['Duplicate customer record in the selected property']], 422);
        }

        DB::transaction(function () use ($customer, $data, $property) {
            $customer->fill([
                'property_id' => $property?->id ?? $customer->property_id,
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
            new CustomerResource($customer->fresh()->load(['property', 'businessDetail'])->loadCount('contracts')),
            ApiMessages::updated('customer')
        );
    }

    /**
     * Handle the destroy request.
     */
    public function destroy(Customer $customer)
    {
        $this->authorize('delete', $customer);

        DB::transaction(fn () => $customer->delete());

        return ApiResponse::success(ApiMessages::deleted('customer'));
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
     * Sync business detail.
     */
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

    /**
     * Normalize display name.
     */
    private function normalizeDisplayName(?string $value): ?string
    {
        $value = Str::of((string) $value)->trim()->squish()->toString();

        return $value !== '' ? $value : null;
    }

    /**
     * Normalize email.
     */
    private function normalizeEmail(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? Str::lower($value) : null;
    }

    /**
     * Normalize phone.
     */
    private function normalizePhone(?string $value): ?string
    {
        $value = preg_replace('/[^0-9+]/', '', (string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * Normalize business code.
     */
    private function normalizeBusinessCode(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? Str::upper($value) : null;
    }
}
