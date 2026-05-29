<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\InteractsWithTenantAdminModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\V1\TenantIndexRequest;
use App\Http\Requests\Api\Admin\V1\AssignTenantBillingProfileRequest;
use App\Http\Requests\Api\Admin\V1\TenantStaffIndexRequest;
use App\Http\Requests\Api\Admin\V1\TenantSubscriptionPropertyIndexRequest;
use App\Http\Requests\Api\Admin\V1\TenantStoreRequest;
use App\Http\Requests\Api\Admin\V1\TenantStatusUpdateRequest;
use App\Http\Requests\Api\Admin\V1\TenantSubscriptionStatusUpdateRequest;
use App\Http\Resources\Admin\V1\TenantResource;
use App\Http\Resources\App\V1\TenantUserResource;
use App\Http\Resources\App\V1\WorkspaceSubscriptionPropertyResource;
use App\Http\Resources\App\V1\WorkspaceSubscriptionResource;
use App\Models\Landlord\BaseUser;
use App\Models\Landlord\BillingProfile;
use App\Models\Landlord\UserTenant;
use App\Models\Tenant\User;
use App\Models\Tenancy\Tenant;
use App\Services\V1\SubscriptionBillingProfileChangeService;
use App\Services\V1\SubscriptionService;
use App\Services\V1\TenantProvisioningService;
use App\Services\V1\WorkspaceService;
use App\Support\ApiResponse;
use InvalidArgumentException;

class TenantController extends Controller
{
    use InteractsWithTenantAdminModels;

    public function __construct(
        private SubscriptionService $subscriptionService,
        private SubscriptionBillingProfileChangeService $subscriptionBillingProfileChangeService,
        private TenantProvisioningService $tenantProvisioningService,
        private WorkspaceService $workspaceService,
    )
    {
    }

    /** List workspaces for the admin area with lightweight filters for operational search. */
    public function index(TenantIndexRequest $request)
    {
        $filters = $request->validated();
        $query = Tenant::query();

        if (!empty($filters['search'] ?? null)) {
            $query->where('name', 'like', $filters['search'].'%');
        }

        if (!empty($filters['name'] ?? null)) {
            $query->where('name', 'like', $filters['name'].'%');
        }

        if (!empty($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['provisioning_status'] ?? null)) {
            $query->where('provisioning_status', $filters['provisioning_status']);
        }

        $perPage = (int) ($filters['per_page'] ?? 15);
        $tenants = $query->orderBy('name')->paginate($perPage);

        return ApiResponse::resource(TenantResource::collection($tenants), 'Tenants list');
    }

    /** Return one workspace record for admin detail screens. */
    public function show(Tenant $tenant)
    {
        return ApiResponse::resource(new TenantResource($tenant), 'Tenant details');
    }

    /** List staff inside a specific workspace so admin users can inspect tenant membership. */
    public function staff(TenantStaffIndexRequest $request, Tenant $tenant)
    {
        $tenantUsers = $this->runInTenantContext($tenant, function () use ($request) {
            $filters = $request->validated();
            $query = User::query()->with([
                'baseUser:id,uuid,username,email,phone,status',
                'roles',
            ]);

            if (!empty($filters['name'] ?? null)) {
                $query->where('name', 'like', $filters['name'].'%');
            }

            if (!empty($filters['phone'] ?? null)) {
                $query->where('phone', 'like', $filters['phone'].'%');
            }

            if (!empty($filters['search'] ?? null)) {
                $this->applyTenantPrefixSearch($query, $filters['search'], ['name', 'email', 'phone']);
            }

            if (!empty($filters['status'] ?? null)) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['role'] ?? null)) {
                $query->role($filters['role']);
            }

            $this->applyTenantSort($query, $filters['sort'] ?? null, ['name', 'created_at'], 'name', 'asc');

            return $query->paginate((int) ($filters['per_page'] ?? 15));
        });

        return ApiResponse::resource(TenantUserResource::collection($tenantUsers), 'Tenant staff list');
    }

    /** Create a new workspace from the admin side and queue provisioning of its tenant database. */
    public function store(TenantStoreRequest $request)
    {
        $data = $request->validated();
        $owner = BaseUser::query()->where('uuid', $data['owner_uuid'])->first();
        if (!$owner) {
            return ApiResponse::error('Owner not found', ['owner_uuid' => ['Invalid owner account']], 422);
        }

        if (!empty($data['billing_profile_uuid'] ?? null)
            && !BillingProfile::query()->where('uuid', $data['billing_profile_uuid'])->exists()) {
            return ApiResponse::error(
                'Workspace could not be created.',
                ['billing_profile_uuid' => ['The selected billing profile could not be found.']],
                422
            );
        }

        try {
            $tenant = $this->workspaceService->createWorkspaceForUser($owner, $data, 'admin');
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Workspace could not be created.',
                $this->mapWorkspaceCreationError($exception->getMessage()),
                422
            );
        }

        $this->tenantProvisioningService->dispatchProvisioning($tenant, $owner->id, $data['plan_uuid'] ?? null);

        return ApiResponse::resource(new TenantResource($tenant->fresh()), 'Primary workspace created. Provisioning queued.', 202);
    }

    /** Retry workspace provisioning when the original async setup did not complete successfully. */
    public function retryProvisioning(Tenant $tenant)
    {
        if ($tenant->provisioning_status === 'provisioning') {
            return ApiResponse::error('Tenant provisioning already in progress', ['tenant' => ['This tenant is currently being provisioned.']], 422);
        }

        $ownerId = UserTenant::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_owner', true)
            ->value('user_id');

        if (!$ownerId) {
            return ApiResponse::error('Tenant provisioning retry failed', ['tenant' => ['Tenant owner link could not be resolved.']], 422);
        }

        $planUuid = data_get($tenant->meta, 'plan_uuid');
        $tenant = $this->tenantProvisioningService->retry($tenant, (int) $ownerId, $planUuid);

        return ApiResponse::resource(new TenantResource($tenant), 'Tenant provisioning retry queued', 202);
    }

    /** Return the current subscription summary and apply any due scheduled billing profile change first. */
    public function subscription(Tenant $tenant)
    {
        $this->subscriptionBillingProfileChangeService->applyDuePendingChangeIfNeeded($tenant);

        return ApiResponse::resource(
            new WorkspaceSubscriptionResource($this->subscriptionService->getWorkspaceSubscriptionSummary($tenant)),
            'Workspace subscription details retrieved successfully.'
        );
    }

    /** Return the paginated property-level billing estimate breakdown for one workspace. */
    public function subscriptionProperties(TenantSubscriptionPropertyIndexRequest $request, Tenant $tenant)
    {
        $this->subscriptionBillingProfileChangeService->applyDuePendingChangeIfNeeded($tenant);

        $properties = $this->subscriptionService->getWorkspaceSubscriptionPropertyBreakdown(
            $tenant,
            $request->validated()
        );

        return ApiResponse::resource(
            WorkspaceSubscriptionPropertyResource::collection($properties),
            'Workspace subscription property breakdown retrieved successfully.'
        );
    }

    /** Suspend or reactivate a workspace at the tenant record level. */
    public function updateStatus(TenantStatusUpdateRequest $request, Tenant $tenant)
    {
        $tenant->status = $request->validated('status');
        $tenant->save();

        return ApiResponse::resource(new TenantResource($tenant->fresh()), 'Workspace status updated successfully.');
    }

    /** Change lifecycle status of the current workspace subscription such as trialing, active, or canceled. */
    public function updateSubscriptionStatus(TenantSubscriptionStatusUpdateRequest $request, Tenant $tenant)
    {
        $subscription = $this->subscriptionService->updateSubscriptionStatus($tenant, $request->validated());
        $this->subscriptionBillingProfileChangeService->applyDuePendingChangeIfNeeded($tenant);

        if (!$subscription) {
            return ApiResponse::notFound(
                ['subscription' => ['No active subscription record was found for this workspace.']],
                'Workspace subscription could not be found.'
            );
        }

        return ApiResponse::resource(
            new WorkspaceSubscriptionResource($this->subscriptionService->getWorkspaceSubscriptionSummary($tenant)),
            'Workspace subscription status updated successfully.'
        );
    }

    /** Preview billing profile impact so admins can review pricing and proration before applying a change. */
    public function previewBillingProfileChange(AssignTenantBillingProfileRequest $request, Tenant $tenant)
    {
        $profile = BillingProfile::query()->where('uuid', $request->validated('billing_profile_uuid'))->first();

        if (!$profile) {
            return ApiResponse::error(
                'Billing profile preview failed.',
                ['billing_profile_uuid' => ['The selected billing profile could not be found.']],
                422
            );
        }

        try {
            $preview = $this->subscriptionBillingProfileChangeService->preview($tenant, $profile, $request->validated());
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Billing profile preview failed.',
                ['billing_profile' => [$exception->getMessage()]],
                422
            );
        }

        return ApiResponse::resource($preview, 'Workspace billing profile change preview generated successfully.');
    }

    /** Apply a billing profile change immediately or schedule it for the next billing cycle. */
    public function assignBillingProfile(AssignTenantBillingProfileRequest $request, Tenant $tenant)
    {
        $profile = BillingProfile::query()->where('uuid', $request->validated('billing_profile_uuid'))->first();

        if (!$profile) {
            return ApiResponse::error(
                'Billing profile assignment failed.',
                ['billing_profile_uuid' => ['The selected billing profile could not be found.']],
                422
            );
        }

        try {
            $this->subscriptionBillingProfileChangeService->apply($tenant, $profile, $request->validated());
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Billing profile assignment failed.',
                ['billing_profile' => [$exception->getMessage()]],
                422
            );
        }

        return ApiResponse::resource(
            new WorkspaceSubscriptionResource($this->subscriptionService->getWorkspaceSubscriptionSummary($tenant->fresh())),
            'Workspace billing profile assigned successfully.'
        );
    }

    private function mapWorkspaceCreationError(string $message): array
    {
        return match ($message) {
            'This account already has a workspace. Add properties inside the existing workspace instead of creating another database.' => [
                'tenant' => ['This account already has a workspace. Add properties inside the existing workspace instead.'],
            ],
            'Workspace with the same name or database already exists.' => [
                'name' => ['A workspace with the same name already exists.'],
            ],
            default => [
                'tenant' => ['Please review the submitted workspace details and try again.'],
            ],
        };
    }
}
