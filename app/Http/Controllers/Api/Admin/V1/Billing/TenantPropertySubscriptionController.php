<?php

namespace App\Http\Controllers\Api\Admin\V1\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\V1\Billing\TenantPropertySubscriptionIndexRequest;
use App\Http\Requests\Api\Admin\V1\Billing\TenantPropertySubscriptionPaymentIndexRequest;
use App\Http\Resources\Admin\V1\Billing\PropertySubscriptionPaymentResource;
use App\Http\Resources\Admin\V1\Billing\WorkspacePropertySubscriptionResource;
use App\Models\Tenancy\Tenant;
use App\Services\V1\Billing\PropertySubscriptionService;
use App\Support\ApiResponse;
use InvalidArgumentException;

class TenantPropertySubscriptionController extends Controller
{
    public function __construct(
        private PropertySubscriptionService $propertySubscriptionService,
    ) {
    }

    public function index(TenantPropertySubscriptionIndexRequest $request, Tenant $tenant)
    {
        try {
            $subscriptions = $this->propertySubscriptionService->listTenantPropertySubscriptions($tenant, $request->validated());
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Workspace property subscriptions could not be retrieved.',
                ['workspace' => [$exception->getMessage()]],
                422
            );
        }

        return ApiResponse::resource(
            WorkspacePropertySubscriptionResource::collection($subscriptions),
            'Workspace property subscriptions retrieved successfully.'
        );
    }

    public function show(Tenant $tenant, string $propertyUuid)
    {
        try {
            $subscription = $this->propertySubscriptionService->getTenantPropertySubscription($tenant, $propertyUuid);
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Workspace property subscription could not be retrieved.',
                ['workspace' => [$exception->getMessage()]],
                422
            );
        }

        if (!$subscription) {
            return ApiResponse::notFound(
                ['property_uuid' => ['The selected property subscription could not be found for this workspace.']],
                'Workspace property subscription could not be found.'
            );
        }

        return ApiResponse::resource(
            new WorkspacePropertySubscriptionResource($subscription),
            'Workspace property subscription details retrieved successfully.'
        );
    }

    public function payments(TenantPropertySubscriptionPaymentIndexRequest $request, Tenant $tenant, string $propertyUuid)
    {
        $propertySubscription = $this->propertySubscriptionService->getTenantPropertySubscription($tenant, $propertyUuid);

        if (!$propertySubscription) {
            return ApiResponse::notFound(
                ['property_uuid' => ['The selected property subscription could not be found for this workspace.']],
                'Workspace property subscription could not be found.'
            );
        }

        $payments = $this->propertySubscriptionService->listPayments($tenant, [
            ...$request->validated(),
            'property_uuid' => $propertyUuid,
        ]);

        return ApiResponse::resource(
            PropertySubscriptionPaymentResource::collection($payments),
            'Workspace property subscription payment history retrieved successfully.'
        );
    }
}
