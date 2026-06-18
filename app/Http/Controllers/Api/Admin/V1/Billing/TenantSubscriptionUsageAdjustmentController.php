<?php

namespace App\Http\Controllers\Api\Admin\V1\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\V1\TenantUsageAdjustmentIndexRequest;
use App\Http\Resources\Admin\V1\SubscriptionUsageAdjustmentPreviewResource;
use App\Http\Resources\Admin\V1\SubscriptionUsageAdjustmentResource;
use App\Models\Landlord\SubscriptionUsageAdjustment;
use App\Models\Tenancy\Tenant;
use App\Services\V1\Billing\SubscriptionUsageAdjustmentService;
use App\Support\ApiResponse;
use InvalidArgumentException;

class TenantSubscriptionUsageAdjustmentController extends Controller
{
    /**
     * Create a new instance.
     */
    public function __construct(
        private SubscriptionUsageAdjustmentService $subscriptionUsageAdjustmentService,
    ) {
    }

    /**
     * Handle the preview request.
     */
    public function preview(Tenant $tenant)
    {
        $preview = $this->subscriptionUsageAdjustmentService->previewCurrentAdjustment($tenant);

        return ApiResponse::resource(
            new SubscriptionUsageAdjustmentPreviewResource($preview),
            'Workspace usage adjustment preview retrieved successfully.'
        );
    }

    /**
     * Handle the index request.
     */
    public function index(TenantUsageAdjustmentIndexRequest $request, Tenant $tenant)
    {
        $adjustments = $this->subscriptionUsageAdjustmentService->listAdjustments($tenant, $request->validated());

        return ApiResponse::resource(
            SubscriptionUsageAdjustmentResource::collection($adjustments),
            'Workspace usage adjustments retrieved successfully.'
        );
    }

    /**
     * Handle the apply request.
     */
    public function apply(Tenant $tenant, SubscriptionUsageAdjustment $usageAdjustment)
    {
        if ($usageAdjustment->tenant_id !== $tenant->id) {
            return ApiResponse::notFound(
                ['usage_adjustment' => ['The selected usage adjustment could not be found for this workspace.']],
                'Workspace usage adjustment could not be found.'
            );
        }

        try {
            $adjustment = $this->subscriptionUsageAdjustmentService->applyAdjustment($tenant, $usageAdjustment);
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Workspace usage adjustment could not be applied.',
                ['usage_adjustment' => [$exception->getMessage()]],
                422
            );
        }

        return ApiResponse::resource(
            new SubscriptionUsageAdjustmentResource($adjustment),
            'Workspace usage adjustment applied successfully.'
        );
    }

    /**
     * Handle the waive request.
     */
    public function waive(Tenant $tenant, SubscriptionUsageAdjustment $usageAdjustment)
    {
        if ($usageAdjustment->tenant_id !== $tenant->id) {
            return ApiResponse::notFound(
                ['usage_adjustment' => ['The selected usage adjustment could not be found for this workspace.']],
                'Workspace usage adjustment could not be found.'
            );
        }

        try {
            $adjustment = $this->subscriptionUsageAdjustmentService->waiveAdjustment($tenant, $usageAdjustment);
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Workspace usage adjustment could not be waived.',
                ['usage_adjustment' => [$exception->getMessage()]],
                422
            );
        }

        return ApiResponse::resource(
            new SubscriptionUsageAdjustmentResource($adjustment),
            'Workspace usage adjustment waived successfully.'
        );
    }
}
