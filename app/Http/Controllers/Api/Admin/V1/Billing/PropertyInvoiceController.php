<?php

namespace App\Http\Controllers\Api\Admin\V1\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\V1\Billing\PropertyInvoiceDeliveryLogIndexRequest;
use App\Http\Resources\Admin\V1\Billing\PropertyInvoiceDeliveryLogResource;
use App\Services\V1\Billing\PropertyInvoiceDeliveryLogService;
use App\Support\ApiResponse;

class PropertyInvoiceController extends Controller
{
    /**
     * Create a new instance.
     */
    public function __construct(
        private PropertyInvoiceDeliveryLogService $propertyInvoiceDeliveryLogService,
    ) {
    }

    /**
     * List property invoice delivery logs for admin.
     */
    public function index(PropertyInvoiceDeliveryLogIndexRequest $request)
    {
        $logs = $this->propertyInvoiceDeliveryLogService->listLogs($request->validated());

        return ApiResponse::resource(
            PropertyInvoiceDeliveryLogResource::collection($logs),
            'Property invoice delivery logs retrieved successfully.'
        );
    }
}
