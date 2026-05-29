<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\PreviewWorkspaceBillingProfileChangeRequest;
use App\Http\Requests\Api\App\V1\StoreWorkspaceRequest;
use App\Http\Requests\Api\App\V1\WorkspaceSubscriptionPropertyIndexRequest;
use App\Http\Resources\App\V1\WorkspaceResource;
use App\Http\Resources\App\V1\WorkspaceSubscriptionPropertyResource;
use App\Http\Resources\App\V1\WorkspaceSubscriptionResource;
use App\Models\Landlord\BaseUser;
use App\Models\Landlord\BillingProfile;
use App\Models\Landlord\UserTenant;
use App\Models\Tenancy\Tenant;
use App\Services\V1\SubscriptionBillingProfileChangeService;
use App\Services\V1\SubscriptionService;
use App\Services\V1\TenantProvisioningService;
use App\Services\V1\WorkspaceService;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class WorkspaceController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private SubscriptionBillingProfileChangeService $subscriptionBillingProfileChangeService,
        private TenantProvisioningService $tenantProvisioningService,
        private WorkspaceService $workspaceService,
    )
    {
    }

    /** List workspaces that belong to the authenticated base user. */
    public function index(Request $request)
    {
        $baseUser = $this->resolveBaseUser();

        $workspaces = Tenant::query()
            ->select('tenants.*')
            ->join('user_tenants', 'user_tenants.tenant_id', '=', 'tenants.id')
            ->where('user_tenants.user_id', $baseUser->id)
            ->orderBy('tenants.display_name')
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::resource(WorkspaceResource::collection($workspaces), 'Workspace list');
    }

    /** Create a self-service workspace and queue tenant provisioning for the requester. */
    public function store(StoreWorkspaceRequest $request)
    {
        $baseUser = $this->resolveBaseUser();

        if (!empty($request->validated('billing_profile_uuid'))
            && !BillingProfile::query()->where('uuid', $request->validated('billing_profile_uuid'))->exists()) {
            return ApiResponse::error(
                'Workspace could not be created.',
                ['billing_profile_uuid' => ['The selected billing profile could not be found.']],
                422
            );
        }

        try {
            $tenant = $this->workspaceService->createWorkspaceForUser(
                $baseUser,
                $request->validated(),
                'self_service',
            );
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Workspace could not be created.',
                $this->mapWorkspaceCreationError($exception->getMessage()),
                422
            );
        }

        $this->tenantProvisioningService->dispatchProvisioning($tenant, $baseUser->id, data_get($tenant->meta, 'plan_uuid'));

        return ApiResponse::resource(
            new WorkspaceResource($tenant->fresh()),
            'Primary workspace created. Provisioning queued.',
            202
        );
    }

    /** Return one workspace only if the authenticated base user belongs to it. */
    public function show(Tenant $workspace)
    {
        $baseUser = $this->resolveBaseUser();

        $isMember = UserTenant::query()
            ->where('user_id', $baseUser->id)
            ->where('tenant_id', $workspace->id)
            ->exists();

        if (!$isMember) {
            return ApiResponse::error('Workspace not found', ['workspace' => ['You do not have access to this workspace']], 404);
        }

        return ApiResponse::resource(new WorkspaceResource($workspace), 'Workspace details');
    }

    /** Return the active workspace subscription summary after applying any due scheduled profile change. */
    public function subscription()
    {
        $tenant = request()->attributes->get('tenant');

        if (!$tenant instanceof Tenant) {
            return ApiResponse::serverError(
                ['workspace' => [ApiMessages::TENANT_CONTEXT_UNAVAILABLE]],
                ApiMessages::TENANT_CONTEXT_UNAVAILABLE
            );
        }

        $this->subscriptionBillingProfileChangeService->applyDuePendingChangeIfNeeded($tenant);

        return ApiResponse::resource(
            new WorkspaceSubscriptionResource(
                $this->subscriptionService->getWorkspaceSubscriptionSummary($tenant)
            ),
            'Workspace subscription details retrieved successfully.'
        );
    }

    /** Preview how a billing profile change would affect the current workspace before any write occurs. */
    public function previewBillingProfileChange(PreviewWorkspaceBillingProfileChangeRequest $request)
    {
        $tenant = request()->attributes->get('tenant');

        if (!$tenant instanceof Tenant) {
            return ApiResponse::serverError(
                ['workspace' => [ApiMessages::TENANT_CONTEXT_UNAVAILABLE]],
                ApiMessages::TENANT_CONTEXT_UNAVAILABLE
            );
        }

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

    /** Return paginated property-by-property billing estimates for the current workspace. */
    public function subscriptionProperties(WorkspaceSubscriptionPropertyIndexRequest $request)
    {
        $tenant = request()->attributes->get('tenant');

        if (!$tenant instanceof Tenant) {
            return ApiResponse::serverError(
                ['workspace' => [ApiMessages::TENANT_CONTEXT_UNAVAILABLE]],
                ApiMessages::TENANT_CONTEXT_UNAVAILABLE
            );
        }

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

    private function resolveBaseUser(): BaseUser
    {
        $baseUser = request()->attributes->get('base_user') ?? request()->attributes->get('auth_user');

        if (!$baseUser instanceof BaseUser) {
            abort(401, 'Authenticated base user could not be resolved.');
        }

        return $baseUser;
    }

    private function mapWorkspaceCreationError(string $message): array
    {
        return match ($message) {
            'This account already has a workspace. Add properties inside the existing workspace instead of creating another database.' => [
                'workspace' => ['This account already has a workspace. Add properties inside the existing workspace instead.'],
            ],
            'Workspace with the same name or database already exists.' => [
                'name' => ['A workspace with the same name already exists.'],
            ],
            default => [
                'workspace' => ['Please review the submitted workspace details and try again.'],
            ],
        };
    }
}
