<?php

namespace App\Http\Controllers\Api\Admin\V1\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\V1\Billing\AdminAnalyticsTrendRequest;
use App\Http\Requests\Api\Admin\V1\Billing\AdminTopBillingRulesRequest;
use App\Services\V1\Billing\AdminAnalyticsService;
use App\Support\ApiResponse;

class AdminAnalyticsController extends Controller
{
    /**
     * Create a new instance.
     */
    public function __construct(private AdminAnalyticsService $adminAnalyticsService)
    {
    }

    /** Return collected revenue buckets for admin trend charts. */
    /**
     * Handle revenue trend.
     */
    public function revenueTrend(AdminAnalyticsTrendRequest $request)
    {
        return ApiResponse::success(
            'Admin revenue trend retrieved successfully.',
            $this->adminAnalyticsService->revenueTrend($request->validated())
        );
    }

    /** Return active, expired, and unsubscribed property subscription trend buckets. */
    /**
     * Handle subscription status trend.
     */
    public function subscriptionStatusTrend(AdminAnalyticsTrendRequest $request)
    {
        return ApiResponse::success(
            'Admin subscription status trend retrieved successfully.',
            $this->adminAnalyticsService->subscriptionStatusTrend($request->validated())
        );
    }

    /** Return property onboarding growth buckets for the admin dashboard. */
    /**
     * Handle property growth trend.
     */
    public function propertyGrowthTrend(AdminAnalyticsTrendRequest $request)
    {
        return ApiResponse::success(
            'Admin property growth trend retrieved successfully.',
            $this->adminAnalyticsService->propertyGrowthTrend($request->validated())
        );
    }

    /** Return the current subscription health split for composition charts. */
    /**
     * Handle subscription status split.
     */
    public function subscriptionStatusSplit()
    {
        return ApiResponse::success(
            'Admin subscription status split retrieved successfully.',
            $this->adminAnalyticsService->subscriptionStatusSplit()
        );
    }

    /** Return the highest-performing billing rules in the selected window. */
    /**
     * Handle top billing rules.
     */
    public function topBillingRules(AdminTopBillingRulesRequest $request)
    {
        return ApiResponse::success(
            'Admin top billing rules retrieved successfully.',
            $this->adminAnalyticsService->topBillingRules($request->validated())
        );
    }
}
