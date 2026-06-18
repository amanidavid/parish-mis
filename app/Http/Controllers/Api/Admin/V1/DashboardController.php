<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\V1\PlatformOverviewWorkspaceResource;
use App\Services\V1\PlatformOverviewService;
use App\Support\ApiResponse;

class DashboardController extends Controller
{
    /**
     * Create a new instance.
     */
    public function __construct(private PlatformOverviewService $platformOverviewService)
    {
    }

    /** Return the landlord-only platform overview needed by the admin dashboard in one compact response. */
    /**
     * Handle the overview request.
     */
    public function overview()
    {
        $overview = $this->platformOverviewService->overview();

        return ApiResponse::success('Platform overview retrieved successfully.', [
            'summary' => $overview['summary'],
            'workspaces' => $overview['workspaces'],
            'billing_profiles' => $overview['billing_profiles'],
            'recent_workspaces' => PlatformOverviewWorkspaceResource::collection($overview['recent_workspaces'])->resolve(),
            'generated_at' => $overview['generated_at'],
        ]);
    }
}
