<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\InteractsWithTenantAdminModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\V1\AssignTenantBillingRuleRequest;
use App\Http\Requests\Api\Admin\V1\TenantIndexRequest;
use App\Http\Requests\Api\Admin\V1\AssignTenantBillingProfileRequest;
use App\Http\Requests\Api\Admin\V1\TenantContractsSummaryRequest;
use App\Http\Requests\Api\Admin\V1\TenantPropertyLocationBreakdownRequest;
use App\Http\Requests\Api\Admin\V1\TenantPropertyLocationSummaryRequest;
use App\Http\Requests\Api\Admin\V1\TenantPropertyOverviewRequest;
use App\Http\Requests\Api\Admin\V1\TenantStaffIndexRequest;
use App\Http\Requests\Api\Admin\V1\TenantSubscriptionPropertyIndexRequest;
use App\Http\Requests\Api\Admin\V1\TenantStoreRequest;
use App\Http\Requests\Api\Admin\V1\TenantStatusUpdateRequest;
use App\Http\Requests\Api\Admin\V1\TenantSubscriptionStatusUpdateRequest;
use App\Http\Resources\Admin\V1\TenantPropertyLocationBreakdownResource;
use App\Http\Resources\Admin\V1\TenantPropertyOverviewResource;
use App\Http\Resources\Admin\V1\TenantResource;
use App\Http\Resources\App\V1\TenantUserResource;
use App\Http\Resources\App\V1\WorkspaceSubscriptionPropertyResource;
use App\Http\Resources\App\V1\WorkspaceSubscriptionResource;
use App\Models\Landlord\BaseUser;
use App\Models\Landlord\BillingProfile;
use App\Models\Landlord\UserTenant;
use App\Models\Tenant\User;
use App\Models\Tenancy\Tenant;
use App\Services\V1\Billing\WorkspaceBillingRuleService;
use App\Services\V1\SubscriptionBillingProfileChangeService;
use App\Services\V1\SubscriptionService;
use App\Services\V1\TenantAdminInsightService;
use App\Services\V1\TenantProvisioningService;
use App\Services\V1\WorkspaceService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class TenantController extends Controller
{
    use InteractsWithTenantAdminModels;

    /**
     * Create a new instance.
     */
    public function __construct(
        private SubscriptionService $subscriptionService,
        private TenantAdminInsightService $tenantAdminInsightService,
        private SubscriptionBillingProfileChangeService $subscriptionBillingProfileChangeService,
        private WorkspaceBillingRuleService $workspaceBillingRuleService,
        private TenantProvisioningService $tenantProvisioningService,
        private WorkspaceService $workspaceService,
    )
    {
    }

    /** List workspaces for the admin area with lightweight filters for operational search. */
    /**
     * Handle the index request.
     */
    public function index(TenantIndexRequest $request)
    {
        $filters = $request->validated();
        $query = Tenant::query()->select([
            'id',
            'uuid',
            'name',
            'display_name',
            'database',
            'status',
            'provisioning_status',
            'provision_attempts',
            'provision_error',
            'provision_started_at',
            'provisioned_at',
            'meta',
            'created_at',
            'updated_at',
        ]);

        $this->applyWorkspaceSearch($query, $filters['search'] ?? null);

        if (!empty($filters['name'] ?? null)) {
            $query->where('name', 'like', $filters['name'].'%');
        }

        $this->applyWorkspaceDisplayNamePrefixFilter($query, $filters['display_name'] ?? null);

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
    /**
     * Handle the show request.
     */
    public function show(Tenant $tenant)
    {
        return ApiResponse::resource(new TenantResource($tenant), 'Tenant details');
    }

    /** List staff inside a specific workspace so admin users can inspect tenant membership. */
    /**
     * Handle staff.
     */
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
    /**
     * Handle the store request.
     */
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
    /**
     * Handle the retry provisioning request.
     */
    public function retryProvisioning(Tenant $tenant)
    {
        if ($tenant->provisioning_status === 'provisioning') {
            return ApiResponse::error('Tenant provisioning already in progress', ['tenant' => ['This tenant is currently being provisioned.']], 422);
        }

        if ($tenant->provisioning_status === 'ready') {
            return ApiResponse::error(
                'Tenant provisioning retry is not allowed',
                ['tenant' => ['This workspace is already provisioned and ready. Retry is only available for failed or pending provisioning states.']],
                422
            );
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
    /**
     * Handle the subscription request.
     */
    public function subscription(Tenant $tenant)
    {
        $this->subscriptionBillingProfileChangeService->applyDuePendingChangeIfNeeded($tenant);

        return ApiResponse::resource(
            new WorkspaceSubscriptionResource($this->subscriptionService->getWorkspaceSubscriptionSummary($tenant)),
            'Workspace subscription details retrieved successfully.'
        );
    }

    /** Return the paginated property-level billing estimate breakdown for one workspace. */
    /**
     * Handle subscription properties.
     */
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

    /** Return the core operational counts that describe how a workspace is being used right now. */
    /**
     * Handle operational summary.
     */
    public function operationalSummary(Tenant $tenant)
    {
        $this->subscriptionBillingProfileChangeService->applyDuePendingChangeIfNeeded($tenant);

        $operational = $this->runInTenantContext($tenant, fn () => $this->tenantAdminInsightService->operationalSummary());
        $subscription = $this->subscriptionService->getWorkspaceSubscriptionSummary($tenant);

        return ApiResponse::success('Tenant operational summary retrieved successfully.', [
            'workspace' => [
                'uuid' => $tenant->uuid,
                'name' => $tenant->name,
                'display_name' => $tenant->display_name,
                'database' => $tenant->database,
                'status' => $tenant->status,
                'provisioning_status' => $tenant->provisioning_status,
                'created_at' => $tenant->created_at?->toDateTimeString(),
                'updated_at' => $tenant->updated_at?->toDateTimeString(),
                'access_state' => $subscription['access_state'] ?? null,
                'access_message' => $subscription['access_message'] ?? null,
                'inventory_changes_allowed' => $subscription['inventory_changes_allowed'] ?? null,
                'subscription' => $subscription['subscription'] ?? null,
            ],
            'operational' => $operational,
        ]);
    }

    /** Return grouped property totals across country, region, district, and ward for one workspace. */
    /**
     * Handle property location summary.
     */
    public function propertyLocationSummary(TenantPropertyLocationSummaryRequest $request, Tenant $tenant)
    {
        $summary = $this->runInTenantContext(
            $tenant,
            fn () => $this->tenantAdminInsightService->propertyLocationSummary($request->validated())
        );

        return ApiResponse::success('Tenant property location summary retrieved successfully.', $summary);
    }

    /** Return one paginated location level so admin screens can drill into countries, regions, districts, or wards. */
    /**
     * Handle property location breakdown.
     */
    public function propertyLocationBreakdown(TenantPropertyLocationBreakdownRequest $request, Tenant $tenant)
    {
        $breakdown = $this->runInTenantContext(
            $tenant,
            fn () => $this->tenantAdminInsightService->propertyLocationBreakdown($request->validated())
        );

        $rows = $breakdown['rows'];

        return ApiResponse::success('Tenant property location breakdown retrieved successfully.', [
            'group_by' => $breakdown['group_by'],
            'filters' => $breakdown['filters'],
            'totals' => $breakdown['totals'],
            'data' => TenantPropertyLocationBreakdownResource::collection($rows->getCollection())->resolve(),
            'links' => [
                'first' => $rows->url(1),
                'last' => $rows->url($rows->lastPage()),
                'prev' => $rows->previousPageUrl(),
                'next' => $rows->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $rows->currentPage(),
                'from' => $rows->firstItem(),
                'last_page' => $rows->lastPage(),
                'path' => $rows->path(),
                'per_page' => $rows->perPage(),
                'to' => $rows->lastItem(),
                'total' => $rows->total(),
            ],
        ]);
    }

    /** Return paginated properties with operational rollups for admin workspace inspection screens. */
    /**
     * Handle properties.
     */
    public function properties(TenantPropertyOverviewRequest $request, Tenant $tenant)
    {
        $properties = $this->runInTenantContext(
            $tenant,
            fn () => $this->tenantAdminInsightService->propertyOverview($request->validated())
        );

        return ApiResponse::success('Tenant properties overview retrieved successfully.', [
            'data' => TenantPropertyOverviewResource::collection($properties->getCollection())->resolve(),
            'links' => [
                'first' => $properties->url(1),
                'last' => $properties->url($properties->lastPage()),
                'prev' => $properties->previousPageUrl(),
                'next' => $properties->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $properties->currentPage(),
                'from' => $properties->firstItem(),
                'last_page' => $properties->lastPage(),
                'path' => $properties->path(),
                'per_page' => $properties->perPage(),
                'to' => $properties->lastItem(),
                'total' => $properties->total(),
            ],
        ]);
    }

    /** Return contract totals and status breakdowns for admin workspace monitoring. */
    /**
     * Handle contracts summary.
     */
    public function contractsSummary(TenantContractsSummaryRequest $request, Tenant $tenant)
    {
        $summary = $this->runInTenantContext(
            $tenant,
            fn () => $this->tenantAdminInsightService->contractsSummary($request->validated())
        );

        return ApiResponse::success('Tenant contracts summary retrieved successfully.', $summary);
    }

    /** Return a compact staff status summary without loading individual members. */
    /**
     * Handle staff summary.
     */
    public function staffSummary(Tenant $tenant)
    {
        $summary = $this->runInTenantContext(
            $tenant,
            fn () => $this->tenantAdminInsightService->staffSummary()
        );

        return ApiResponse::success('Tenant staff summary retrieved successfully.', $summary);
    }

    /** Return the effective workspace access state after billing and status rules are applied. */
    /**
     * Handle access state.
     */
    public function accessState(Tenant $tenant)
    {
        $this->subscriptionBillingProfileChangeService->applyDuePendingChangeIfNeeded($tenant);
        $summary = $this->subscriptionService->getWorkspaceSubscriptionSummary($tenant);

        return ApiResponse::success('Tenant access state retrieved successfully.', [
            'workspace_uuid' => $tenant->uuid,
            'workspace_status' => $tenant->status,
            'provisioning_status' => $tenant->provisioning_status,
            'access_state' => $summary['access_state'] ?? null,
            'access_message' => $summary['access_message'] ?? null,
            'inventory_changes_allowed' => $summary['inventory_changes_allowed'] ?? null,
            'subscription' => $summary['subscription'] ?? null,
        ]);
    }

    /** Suspend or reactivate a workspace at the tenant record level. */
    /**
     * Update status.
     */
    public function updateStatus(TenantStatusUpdateRequest $request, Tenant $tenant)
    {
        $tenant->status = $request->validated('status');
        $tenant->save();

        return ApiResponse::resource(new TenantResource($tenant->fresh()), 'Workspace status updated successfully.');
    }

    /** Change lifecycle status of the current workspace subscription such as trialing, active, or canceled. */
    /**
     * Update subscription status.
     */
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
    /**
     * Handle preview billing profile change.
     */
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

        return ApiResponse::success('Workspace billing profile change preview generated successfully.', $preview);
    }

    /**
     * Handle preview billing rule change.
     */
    public function previewBillingRuleChange(AssignTenantBillingRuleRequest $request, Tenant $tenant)
    {
        return ApiResponse::success(
            'Default unit price change preview generated successfully.',
            $this->workspaceBillingRuleService->previewRuleChange(
                (int) $request->validated('unit_price_cents'),
                $request->validated('effective_from'),
                $request->validated('currency')
            )
        );
    }

    /** Apply a billing profile change immediately or schedule it for the next billing cycle. */
    /**
     * Handle assign billing profile.
     */
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

    /**
     * Handle assign billing rule.
     */
    public function assignBillingRule(AssignTenantBillingRuleRequest $request, Tenant $tenant)
    {
        try {
            $billingRule = $this->workspaceBillingRuleService->applyRuleChange(
                (int) $request->validated('unit_price_cents'),
                $request->validated('effective_from'),
                $request->validated('currency')
            );
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Default unit price assignment failed.',
                ['billing_rule' => [$exception->getMessage()]],
                422
            );
        }

        return ApiResponse::success(
            'Default unit price assigned successfully.',
            [
                'billing_rule' => $this->workspaceBillingRuleService->formatRule($billingRule),
            ]
        );
    }

    /**
     * Map workspace creation error.
     */
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

    /**
     * Apply workspace search.
     */
    private function applyWorkspaceSearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);

        if ($search === '') {
            return;
        }

        if (!$this->usesPostgresCaseSensitiveLike($query)) {
            $query->where(function (Builder $searchQuery) use ($search) {
                $searchQuery
                    ->where('name', 'like', $search.'%')
                    ->orWhere('display_name', 'like', $search.'%');
            });

            return;
        }

        $loweredSearch = mb_strtolower($search, 'UTF-8').'%';

        $query->where(function (Builder $searchQuery) use ($search, $loweredSearch) {
            $searchQuery
                ->where('name', 'like', $search.'%')
                ->orWhereRaw('LOWER(display_name) LIKE ?', [$loweredSearch]);
        });
    }

    /**
     * Apply workspace display name prefix filter.
     */
    private function applyWorkspaceDisplayNamePrefixFilter(Builder $query, ?string $displayName): void
    {
        $displayName = trim((string) $displayName);

        if ($displayName === '') {
            return;
        }

        if (!$this->usesPostgresCaseSensitiveLike($query)) {
            $query->where('display_name', 'like', $displayName.'%');

            return;
        }

        $query->whereRaw('LOWER(display_name) LIKE ?', [
            mb_strtolower($displayName, 'UTF-8').'%',
        ]);
    }

    /**
     * Uses postgres case sensitive like.
     */
    private function usesPostgresCaseSensitiveLike(Builder $query): bool
    {
        return $query->getModel()->getConnection()->getDriverName() === 'pgsql';
    }
}
