<?php

namespace App\Http\Controllers\Api\Admin\V1\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\V1\Billing\PreviewPropertySubscriptionPaymentRequest;
use App\Http\Requests\Api\Admin\V1\Billing\StorePropertySubscriptionPaymentRequest;
use App\Http\Requests\Api\Admin\V1\Billing\TenantPropertySubscriptionPaymentIndexRequest;
use App\Http\Resources\Admin\V1\Billing\PropertySubscriptionPaymentResource;
use App\Models\Tenancy\Tenant;
use App\Services\V1\Billing\PropertySubscriptionService;
use App\Support\ApiResponse;
use InvalidArgumentException;

class TenantPropertySubscriptionPaymentController extends Controller
{
    public function __construct(
        private PropertySubscriptionService $propertySubscriptionService,
    ) {
    }

    public function preview(PreviewPropertySubscriptionPaymentRequest $request, Tenant $tenant)
    {
        try {
            $preview = $this->propertySubscriptionService->previewPayment($tenant, $request->validated());
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Property subscription payment preview could not be generated.',
                ['payment' => [$exception->getMessage()]],
                422
            );
        }

        return ApiResponse::success(
            'Property subscription payment preview generated successfully.',
            $preview
        );
    }

    public function index(TenantPropertySubscriptionPaymentIndexRequest $request, Tenant $tenant)
    {
        $payments = $this->propertySubscriptionService->listPayments($tenant, $request->validated());

        return ApiResponse::resource(
            PropertySubscriptionPaymentResource::collection($payments),
            'Workspace property subscription payments retrieved successfully.'
        );
    }

    public function store(StorePropertySubscriptionPaymentRequest $request, Tenant $tenant)
    {
        try {
            $payment = $this->propertySubscriptionService->recordPayment(
                $tenant,
                $request->validated(),
                request()->user()
            );
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Property subscription payment could not be recorded.',
                ['payment' => [$exception->getMessage()]],
                422
            );
        }

        return ApiResponse::resource(
            new PropertySubscriptionPaymentResource($payment),
            'Property subscription payment recorded successfully.',
            201
        );
    }
}
