<?php

namespace App\Http\Controllers\Api\App\V1\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\Billing\PropertyInvoiceIndexRequest;
use App\Http\Requests\Api\App\V1\Billing\WorkspaceInvoiceIndexRequest;
use App\Http\Resources\App\V1\Billing\PropertyInvoiceResource;
use App\Models\Landlord\PropertyInvoice;
use App\Models\Tenant\Property;
use App\Models\Tenant\User;
use App\Models\Tenancy\Tenant;
use App\Services\V1\Billing\PropertyInvoicePdfService;
use App\Services\V1\Billing\PropertyInvoiceService;
use App\Support\ApiResponse;

class PropertyInvoiceController extends Controller
{
    public function __construct(
        private PropertyInvoiceService $propertyInvoiceService,
        private PropertyInvoicePdfService $propertyInvoicePdfService,
    ) {
    }

    public function workspaceIndex(WorkspaceInvoiceIndexRequest $request)
    {
        $this->authorize('viewWorkspace', PropertyInvoice::class);

        $tenant = request()->attributes->get('tenant');
        $tenantUser = request()->user();

        if (!$tenant instanceof Tenant || !$tenantUser instanceof User) {
            return ApiResponse::serverError(
                ['workspace' => ['Workspace is not available right now.']],
                'Workspace is not available right now.'
            );
        }

        $invoices = $this->propertyInvoiceService->listWorkspaceInvoices($tenant, $tenantUser, $request->validated());

        return ApiResponse::resource(
            PropertyInvoiceResource::collection($invoices),
            'Workspace invoices retrieved successfully.'
        );
    }

    public function index(PropertyInvoiceIndexRequest $request, Property $property)
    {
        $this->authorize('viewAny', [PropertyInvoice::class, $property]);

        $tenant = request()->attributes->get('tenant');

        if (!$tenant instanceof Tenant) {
            return ApiResponse::serverError(
                ['workspace' => ['Workspace is not available right now.']],
                'Workspace is not available right now.'
            );
        }

        $invoices = $this->propertyInvoiceService->listPropertyInvoices($tenant, $property->uuid, $request->validated());

        return ApiResponse::resource(
            PropertyInvoiceResource::collection($invoices),
            'Property invoices retrieved successfully.'
        );
    }

    public function preview(Property $property, string $invoiceUuid)
    {
        $tenant = request()->attributes->get('tenant');

        if (!$tenant instanceof Tenant) {
            return ApiResponse::serverError(
                ['workspace' => ['Workspace is not available right now.']],
                'Workspace is not available right now.'
            );
        }

        $invoice = $this->propertyInvoiceService->getPropertyInvoice($tenant, $property->uuid, $invoiceUuid);

        if (!$invoice) {
            return ApiResponse::notFound(
                ['invoice' => ['Invoice not found.']],
                'Invoice not found.'
            );
        }

        $this->authorize('view', [$invoice, $property]);

        return $this->propertyInvoicePdfService->stream($invoice, $tenant->display_name ?: $tenant->name);
    }

    public function download(Property $property, string $invoiceUuid)
    {
        $tenant = request()->attributes->get('tenant');

        if (!$tenant instanceof Tenant) {
            return ApiResponse::serverError(
                ['workspace' => ['Workspace is not available right now.']],
                'Workspace is not available right now.'
            );
        }

        $invoice = $this->propertyInvoiceService->getPropertyInvoice($tenant, $property->uuid, $invoiceUuid);

        if (!$invoice) {
            return ApiResponse::notFound(
                ['invoice' => ['Invoice not found.']],
                'Invoice not found.'
            );
        }

        $this->authorize('download', [$invoice, $property]);

        return $this->propertyInvoicePdfService->download($invoice, $tenant->display_name ?: $tenant->name);
    }
}
