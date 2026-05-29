<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\DashboardOverviewRequest;
use App\Http\Resources\App\V1\DashboardPropertyBreakdownResource;
use App\Models\Tenant\User as TenantUser;
use App\Services\V1\DashboardReportService;
use App\Support\ApiResponse;

class DashboardReportController extends Controller
{
    public function __construct(private DashboardReportService $dashboardReportService)
    {
    }

    /** Return the first-login dashboard dataset with summary cards and paginated property occupancy metrics. */
    public function overview(DashboardOverviewRequest $request)
    {
        $tenantUser = request()->user();

        if (!$tenantUser instanceof TenantUser) {
            return ApiResponse::unauthorized(['user' => ['Authenticated tenant user could not be resolved.']]);
        }

        if (!$tenantUser->hasPermissionTo('reports.view')) {
            return ApiResponse::forbidden(['report' => ['You do not have permission to view dashboard reports.']]);
        }

        $overview = $this->dashboardReportService->overview($tenantUser, $request->validated());
        $propertyBreakdownPaginator = $overview['property_breakdown'];
        $propertyBreakdown = [
            'data' => DashboardPropertyBreakdownResource::collection($propertyBreakdownPaginator->getCollection())->resolve(),
            'links' => [
                'first' => $propertyBreakdownPaginator->url(1),
                'last' => $propertyBreakdownPaginator->url($propertyBreakdownPaginator->lastPage()),
                'prev' => $propertyBreakdownPaginator->previousPageUrl(),
                'next' => $propertyBreakdownPaginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $propertyBreakdownPaginator->currentPage(),
                'from' => $propertyBreakdownPaginator->firstItem(),
                'last_page' => $propertyBreakdownPaginator->lastPage(),
                'path' => $propertyBreakdownPaginator->path(),
                'per_page' => $propertyBreakdownPaginator->perPage(),
                'to' => $propertyBreakdownPaginator->lastItem(),
                'total' => $propertyBreakdownPaginator->total(),
            ],
        ];

        return ApiResponse::success('Dashboard overview retrieved successfully.', [
            'summary' => $overview['summary'],
            'property_breakdown' => $propertyBreakdown,
        ]);
    }
}
