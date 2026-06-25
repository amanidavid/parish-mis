<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\ContractReportByPropertyRequest;
use App\Http\Requests\Api\App\V1\ContractReportChartRequest;
use App\Http\Requests\Api\App\V1\ContractReportExpiringRequest;
use App\Http\Requests\Api\App\V1\ContractReportMonthlyActiveAmountChartRequest;
use App\Http\Requests\Api\App\V1\ContractReportSummaryCardsRequest;
use App\Http\Requests\Api\App\V1\ContractReportSummaryRequest;
use App\Http\Resources\App\V1\ContractExpiringReportResource;
use App\Http\Resources\App\V1\ContractChartBucketResource;
use App\Http\Resources\App\V1\ContractPropertyReportResource;
use App\Models\Tenant\User as TenantUser;
use App\Services\V1\ContractReportService;
use App\Support\ApiResponse;

class ContractReportController extends Controller
{
    /**
     * Create a new instance.
     */
    public function __construct(private ContractReportService $contractReportService)
    {
    }

    /**
     * Handle the summary request.
     */
    public function summary(ContractReportSummaryRequest $request)
    {
        $tenantUser = $this->resolveTenantUser();
        if (!$tenantUser instanceof TenantUser) {
            return $tenantUser;
        }

        return ApiResponse::success(
            'Contract report summary retrieved successfully.',
            $this->contractReportService->summary($tenantUser, $request->validated())
        );
    }

    /**
     * Handle the summary cards request.
     */
    public function summaryCards(ContractReportSummaryCardsRequest $request)
    {
        $tenantUser = $this->resolveTenantUser();
        if (!$tenantUser instanceof TenantUser) {
            return $tenantUser;
        }

        return ApiResponse::success(
            'Contract summary cards retrieved successfully.',
            $this->contractReportService->summaryCards($tenantUser, $request->validated())
        );
    }

    /**
     * Handle the by property request.
     */
    public function byProperty(ContractReportByPropertyRequest $request)
    {
        $tenantUser = $this->resolveTenantUser();
        if (!$tenantUser instanceof TenantUser) {
            return $tenantUser;
        }

        $paginator = $this->contractReportService->byProperty($tenantUser, $request->validated());

        return ApiResponse::success('Contract report by property retrieved successfully.', [
            'data' => ContractPropertyReportResource::collection($paginator->getCollection())->resolve(),
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
     * Handle the chart request.
     */
    public function chart(ContractReportChartRequest $request)
    {
        $tenantUser = $this->resolveTenantUser();
        if (!$tenantUser instanceof TenantUser) {
            return $tenantUser;
        }

        $report = $this->contractReportService->chart($tenantUser, $request->validated());

        return ApiResponse::success('Property contract chart retrieved successfully.', [
            'filters' => $report['filters'],
            'property' => $report['property'],
            'summary' => $report['summary'],
            'series' => ContractChartBucketResource::collection(collect($report['series']))->resolve(),
        ]);
    }

    /**
     * Handle the monthly active amount chart request.
     */
    public function monthlyActiveAmountChart(ContractReportMonthlyActiveAmountChartRequest $request)
    {
        $tenantUser = $this->resolveTenantUser();
        if (!$tenantUser instanceof TenantUser) {
            return $tenantUser;
        }

        return ApiResponse::success(
            'Monthly active contract amount chart retrieved successfully.',
            $this->contractReportService->monthlyActiveAmountChart($tenantUser, $request->validated())
        );
    }

    /**
     * Handle the expiring request.
     */
    public function expiring(ContractReportExpiringRequest $request)
    {
        $tenantUser = $this->resolveTenantUser();
        if (!$tenantUser instanceof TenantUser) {
            return $tenantUser;
        }

        $paginator = $this->contractReportService->expiring($tenantUser, $request->validated());

        return ApiResponse::success('Expiring contracts report retrieved successfully.', [
            'data' => ContractExpiringReportResource::collection($paginator->getCollection())->resolve(),
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
            return ApiResponse::forbidden(['report' => ['You do not have permission to view contract reports.']]);
        }

        return $tenantUser;
    }
}
