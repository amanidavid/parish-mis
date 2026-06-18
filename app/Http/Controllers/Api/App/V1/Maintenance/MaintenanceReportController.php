<?php

namespace App\Http\Controllers\Api\App\V1\Maintenance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\Maintenance\MaintenanceExpenseByPropertyRequest;
use App\Http\Requests\Api\App\V1\Maintenance\MaintenanceExpenseSummaryRequest;
use App\Http\Requests\Api\App\V1\Maintenance\RecentMaintenanceExpenseRequest;
use App\Http\Resources\App\V1\Maintenance\MaintenanceExpensePropertyReportResource;
use App\Http\Resources\App\V1\Maintenance\MaintenanceRecentExpenseReportResource;
use App\Models\Tenant\User as TenantUser;
use App\Services\V1\Maintenance\MaintenanceReportService;
use App\Support\ApiResponse;

class MaintenanceReportController extends Controller
{
    /**
     * Create a new instance.
     */
    public function __construct(private MaintenanceReportService $maintenanceReportService)
    {
    }

    /**
     * Handle the summary request.
     */
    public function summary(MaintenanceExpenseSummaryRequest $request)
    {
        $tenantUser = $this->resolveTenantUser();
        if (!$tenantUser instanceof TenantUser) {
            return $tenantUser;
        }

        return ApiResponse::success(
            'Maintenance expense summary retrieved successfully.',
            $this->maintenanceReportService->summary($tenantUser, $request->validated())
        );
    }

    /**
     * Handle the by property request.
     */
    public function byProperty(MaintenanceExpenseByPropertyRequest $request)
    {
        $tenantUser = $this->resolveTenantUser();
        if (!$tenantUser instanceof TenantUser) {
            return $tenantUser;
        }

        $paginator = $this->maintenanceReportService->byProperty($tenantUser, $request->validated());

        return ApiResponse::success('Maintenance expenses by property retrieved successfully.', [
            'data' => MaintenanceExpensePropertyReportResource::collection($paginator->getCollection())->resolve(),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'path' => $paginator->path(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Handle recent expenses.
     */
    public function recentExpenses(RecentMaintenanceExpenseRequest $request)
    {
        $tenantUser = $this->resolveTenantUser();
        if (!$tenantUser instanceof TenantUser) {
            return $tenantUser;
        }

        $paginator = $this->maintenanceReportService->recentExpenses($tenantUser, $request->validated());

        return ApiResponse::success('Recent maintenance expenses retrieved successfully.', [
            'data' => MaintenanceRecentExpenseReportResource::collection($paginator->getCollection())->resolve(),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'path' => $paginator->path(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Resolve tenant user.
     */
    private function resolveTenantUser(): TenantUser|\Illuminate\Http\JsonResponse
    {
        $tenantUser = request()->user();

        if (!$tenantUser instanceof TenantUser) {
            return ApiResponse::unauthorized(['user' => ['Authenticated tenant user could not be resolved.']]);
        }

        if (!$tenantUser->hasPermissionTo('reports.view')) {
            return ApiResponse::forbidden(['report' => ['You do not have permission to view maintenance reports.']]);
        }

        return $tenantUser;
    }
}
