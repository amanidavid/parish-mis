<?php

namespace App\Services\V1;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PlatformOverviewService
{
    private const CACHE_KEY = 'admin.platform.overview.v1';

    private const CACHE_TTL_SECONDS = 30;

    private const RECENT_WORKSPACES_LIMIT = 10;
    private const TENANT_COLUMNS = [
        'uuid',
        'name',
        'display_name',
        'database',
        'status',
        'provisioning_status',
        'created_at',
    ];

    /**
     * Handle the overview request.
     */
    public function overview(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn () => $this->buildOverview()
        );
    }

    /**
     * Build overview.
     */
    private function buildOverview(): array
    {
        $tenantOverview = DB::connection('base')
            ->table('tenants')
            ->selectRaw('COUNT(*) as total_workspaces')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_workspaces")
            ->selectRaw("SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_workspaces")
            ->selectRaw("SUM(CASE WHEN provisioning_status = 'pending' THEN 1 ELSE 0 END) as pending_workspaces")
            ->selectRaw("SUM(CASE WHEN provisioning_status = 'provisioning' THEN 1 ELSE 0 END) as provisioning_workspaces")
            ->selectRaw("SUM(CASE WHEN provisioning_status = 'ready' THEN 1 ELSE 0 END) as ready_workspaces")
            ->selectRaw("SUM(CASE WHEN provisioning_status = 'failed' THEN 1 ELSE 0 END) as failed_workspaces")
            ->first();

        $billingRuleOverview = DB::connection('base')
            ->table('billing_rules')
            ->selectRaw('COUNT(*) as total_billing_rules')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_billing_rules")
            ->selectRaw("SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_billing_rules")
            ->first();

        $recentWorkspaces = DB::connection('base')
            ->table('tenants')
            ->select(self::TENANT_COLUMNS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_WORKSPACES_LIMIT)
            ->get();

        return [
            'summary' => [
                'total_workspaces' => (int) ($tenantOverview->total_workspaces ?? 0),
                'active_workspaces' => (int) ($tenantOverview->active_workspaces ?? 0),
                'suspended_workspaces' => (int) ($tenantOverview->suspended_workspaces ?? 0),
                'provisioning_failed_workspaces' => (int) ($tenantOverview->failed_workspaces ?? 0),
                'total_billing_rules' => (int) ($billingRuleOverview->total_billing_rules ?? 0),
                'active_billing_rules' => (int) ($billingRuleOverview->active_billing_rules ?? 0),
            ],
            'workspaces' => [
                'total' => (int) ($tenantOverview->total_workspaces ?? 0),
                'active' => (int) ($tenantOverview->active_workspaces ?? 0),
                'suspended' => (int) ($tenantOverview->suspended_workspaces ?? 0),
                'pending' => (int) ($tenantOverview->pending_workspaces ?? 0),
                'provisioning' => (int) ($tenantOverview->provisioning_workspaces ?? 0),
                'ready' => (int) ($tenantOverview->ready_workspaces ?? 0),
                'failed' => (int) ($tenantOverview->failed_workspaces ?? 0),
            ],
            'billing_rules' => [
                'total' => (int) ($billingRuleOverview->total_billing_rules ?? 0),
                'active' => (int) ($billingRuleOverview->active_billing_rules ?? 0),
                'inactive' => (int) ($billingRuleOverview->inactive_billing_rules ?? 0),
            ],
            'recent_workspaces' => $recentWorkspaces,
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }
}
