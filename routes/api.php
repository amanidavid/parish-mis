<?php

use App\Http\Controllers\Api\Admin\V1\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\V1\Billing\AutomationTaskController;
use App\Http\Controllers\Api\Admin\V1\Billing\AdminAnalyticsController;
use App\Http\Controllers\Api\Admin\V1\Billing\PropertySubscriptionReportController;
use App\Http\Controllers\Api\Admin\V1\Billing\TenantPropertySubscriptionController;
use App\Http\Controllers\Api\Admin\V1\Billing\TenantPropertySubscriptionPaymentController;
use App\Http\Controllers\Api\Admin\V1\Billing\TenantSubscriptionUsageAdjustmentController;
use App\Http\Controllers\Api\Admin\V1\BillingProfileController;
use App\Http\Controllers\Api\Admin\V1\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\V1\TenantController;
use App\Http\Controllers\Api\App\V1\AccessControlController;
use App\Http\Controllers\Api\App\V1\AuthController as AppAuthController;
use App\Http\Controllers\Api\App\V1\CustomerContractController;
use App\Http\Controllers\Api\App\V1\CustomerController;
use App\Http\Controllers\Api\App\V1\ContractReportController;
use App\Http\Controllers\Api\App\V1\DashboardReportController;
use App\Http\Controllers\Api\App\V1\LocationController;
use App\Http\Controllers\Api\App\V1\Maintenance\MaintenanceExpenseController;
use App\Http\Controllers\Api\App\V1\Maintenance\MaintenanceJobController;
use App\Http\Controllers\Api\App\V1\Maintenance\MaintenanceReportController;
use App\Http\Controllers\Api\App\V1\PropertyController;
use App\Http\Controllers\Api\App\V1\PropertyFloorController;
use App\Http\Controllers\Api\App\V1\PropertyTypeController;
use App\Http\Controllers\Api\App\V1\StaffPropertyAssignmentController;
use App\Http\Controllers\Api\App\V1\TenantUserController;
use App\Http\Controllers\Api\App\V1\UnitController;
use App\Http\Controllers\Api\App\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('admin')
        ->middleware('throttle:api')
        ->group(function () {
            Route::prefix('auth')->group(function () {
                Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:login');
                Route::post('forgot-password', [AdminAuthController::class, 'forgotPassword'])->middleware('throttle:login');
                Route::post('reset-password', [AdminAuthController::class, 'resetPassword'])->middleware('throttle:login');
                Route::post('logout', [AdminAuthController::class, 'logout'])->middleware('admin.jwt.auth');
                Route::get('me', [AdminAuthController::class, 'me'])->middleware('admin.jwt.auth');
            });

            Route::middleware('admin.jwt.auth')->group(function () {
                Route::get('platform/overview', [AdminDashboardController::class, 'overview']);
                Route::prefix('analytics')->group(function () {
                    Route::get('revenue-trend', [AdminAnalyticsController::class, 'revenueTrend']);
                    Route::get('subscription-status-trend', [AdminAnalyticsController::class, 'subscriptionStatusTrend']);
                    Route::get('property-growth-trend', [AdminAnalyticsController::class, 'propertyGrowthTrend']);
                    Route::get('subscription-status-split', [AdminAnalyticsController::class, 'subscriptionStatusSplit']);
                    Route::get('top-billing-rules', [AdminAnalyticsController::class, 'topBillingRules']);
                });
                Route::get('billing-rules', [BillingProfileController::class, 'indexRules']);
                Route::get('billing-profiles', [BillingProfileController::class, 'index']);
                Route::post('billing-profiles', [BillingProfileController::class, 'store']);
                Route::get('billing-profiles/{billingProfile}', [BillingProfileController::class, 'show']);
                Route::patch('billing-profiles/{billingProfile}', [BillingProfileController::class, 'update']);
                Route::get('billing-profiles/{billingProfile}/rules', [BillingProfileController::class, 'rules']);
                Route::post('billing-profiles/{billingProfile}/rules', [BillingProfileController::class, 'storeRule']);
                Route::patch('billing-rules/{billingRule}', [BillingProfileController::class, 'updateRule']);
                Route::get('tenants', [TenantController::class, 'index']);
                Route::post('tenants', [TenantController::class, 'store']);
                Route::get('tenants/{tenant}', [TenantController::class, 'show']);
                Route::get('tenants/{tenant}/staff', [TenantController::class, 'staff']);
                Route::get('tenants/{tenant}/staff/summary', [TenantController::class, 'staffSummary']);
                Route::get('tenants/{tenant}/operational-summary', [TenantController::class, 'operationalSummary']);
                Route::get('tenants/{tenant}/access-state', [TenantController::class, 'accessState']);
                Route::get('tenants/{tenant}/properties', [TenantController::class, 'properties']);
                Route::get('tenants/{tenant}/properties/location-summary', [TenantController::class, 'propertyLocationSummary']);
                Route::get('tenants/{tenant}/properties/location-breakdown', [TenantController::class, 'propertyLocationBreakdown']);
                Route::get('tenants/{tenant}/contracts/summary', [TenantController::class, 'contractsSummary']);
                Route::get('tenants/{tenant}/subscription', [TenantController::class, 'subscription']);
                Route::get('tenants/{tenant}/subscription/properties', [TenantController::class, 'subscriptionProperties']);
                Route::get('tenants/{tenant}/property-subscriptions', [TenantPropertySubscriptionController::class, 'index']);
                Route::get('tenants/{tenant}/property-subscriptions/{propertyUuid}', [TenantPropertySubscriptionController::class, 'show']);
                Route::get('tenants/{tenant}/property-subscriptions/{propertyUuid}/payments', [TenantPropertySubscriptionController::class, 'payments']);
                Route::get('tenants/{tenant}/property-subscription-payments', [TenantPropertySubscriptionPaymentController::class, 'index']);
                Route::post('tenants/{tenant}/property-subscription-payments/preview', [TenantPropertySubscriptionPaymentController::class, 'preview']);
                Route::post('tenants/{tenant}/property-subscription-payments', [TenantPropertySubscriptionPaymentController::class, 'store']);
                Route::get('tenants/{tenant}/usage-adjustments/preview', [TenantSubscriptionUsageAdjustmentController::class, 'preview']);
                Route::get('tenants/{tenant}/usage-adjustments', [TenantSubscriptionUsageAdjustmentController::class, 'index']);
                Route::post('tenants/{tenant}/usage-adjustments/{usageAdjustment}/apply', [TenantSubscriptionUsageAdjustmentController::class, 'apply']);
                Route::post('tenants/{tenant}/usage-adjustments/{usageAdjustment}/waive', [TenantSubscriptionUsageAdjustmentController::class, 'waive']);
                Route::post('tenants/{tenant}/billing-rule/preview', [TenantController::class, 'previewBillingRuleChange']);
                Route::patch('tenants/{tenant}/billing-rule', [TenantController::class, 'assignBillingRule']);
                Route::post('tenants/{tenant}/billing-profile/preview', [TenantController::class, 'previewBillingProfileChange']);
                Route::patch('tenants/{tenant}/billing-profile', [TenantController::class, 'assignBillingProfile']);
                Route::patch('tenants/{tenant}/status', [TenantController::class, 'updateStatus']);
                Route::patch('tenants/{tenant}/subscription-status', [TenantController::class, 'updateSubscriptionStatus']);
                Route::post('tenants/{tenant}/retry-provisioning', [TenantController::class, 'retryProvisioning']);
                Route::get('reports/property-subscription-payments/summary', [PropertySubscriptionReportController::class, 'paymentSummary']);
                Route::get('reports/property-subscriptions/by-workspace', [PropertySubscriptionReportController::class, 'byWorkspace']);
                Route::get('reports/property-subscriptions/expired', [PropertySubscriptionReportController::class, 'expired']);
                Route::get('automation/tasks', [AutomationTaskController::class, 'index']);
                Route::get('automation/tasks/{automationTaskSetting}/runs', [AutomationTaskController::class, 'runs']);
                Route::patch('automation/tasks/{automationTaskSetting}', [AutomationTaskController::class, 'update']);
                Route::post('automation/tasks/{automationTaskSetting}/run-now', [AutomationTaskController::class, 'runNow']);
            });
        });

    Route::prefix('app')
        ->middleware('throttle:api')
        ->group(function () {
            Route::prefix('auth')->group(function () {
                Route::post('register', [AppAuthController::class, 'register'])->middleware('throttle:login');
                Route::post('login', [AppAuthController::class, 'login'])->middleware('throttle:login');
                Route::post('verify-otp', [AppAuthController::class, 'verifyOtp'])->middleware('throttle:login');
                Route::post('forgot-password', [AppAuthController::class, 'forgotPassword'])->middleware('throttle:login');
                Route::post('reset-password', [AppAuthController::class, 'resetPassword'])->middleware('throttle:login');
                Route::post('change-password', [AppAuthController::class, 'changePassword'])->middleware('jwt.auth');
                Route::patch('profile', [AppAuthController::class, 'updateProfile'])->middleware('jwt.auth');
                Route::post('refresh', [AppAuthController::class, 'refresh'])->middleware(['jwt.auth', 'throttle:refresh']);
                Route::post('logout', [AppAuthController::class, 'logout'])->middleware('jwt.auth');
            });

            // Route::middleware('jwt.auth')->group(function () {
            //     Route::get('workspaces', [WorkspaceController::class, 'index']);
            //     Route::post('workspaces', [WorkspaceController::class, 'store']);
            //     Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show']);
            // });

            Route::middleware(['tenant', 'jwt.auth'])->group(function () {
                Route::get('auth/me', [AppAuthController::class, 'me']);
                Route::get('reports/dashboard-overview', [DashboardReportController::class, 'overview']);
                Route::prefix('reports/contracts')->group(function () {
                    Route::get('summary', [ContractReportController::class, 'summary']);
                    Route::get('by-property', [ContractReportController::class, 'byProperty']);
                    Route::get('chart', [ContractReportController::class, 'chart']);
                    Route::get('expiring', [ContractReportController::class, 'expiring']);
                });
                Route::prefix('reports/maintenance')->group(function () {
                    Route::get('summary', [MaintenanceReportController::class, 'summary']);
                    Route::get('by-property', [MaintenanceReportController::class, 'byProperty']);
                    Route::get('recent-expenses', [MaintenanceReportController::class, 'recentExpenses']);
                });
                Route::get('workspace/subscription', [WorkspaceController::class, 'subscription']);
                Route::post('workspace/subscription/billing-profile/preview', [WorkspaceController::class, 'previewBillingProfileChange']);
                Route::get('workspace/subscription/properties', [WorkspaceController::class, 'subscriptionProperties']);
                Route::get('workspace/subscription/properties/cost-breakdown', [WorkspaceController::class, 'propertyCostBreakdown']);
                Route::get('workspace/subscription/properties/{propertyUuid}/cost-breakdown', [WorkspaceController::class, 'propertyCostBreakdownShow']);
                Route::get('workspace/subscription/properties/{propertyUuid}/payments', [WorkspaceController::class, 'propertyCostBreakdownPayments']);
                Route::prefix('access-control')->group(function () {
                    Route::get('permissions', [AccessControlController::class, 'permissions']);
                    Route::post('permissions', [AccessControlController::class, 'storePermission']);
                    Route::get('roles', [AccessControlController::class, 'roles']);
                    Route::post('roles', [AccessControlController::class, 'storeRole']);
                    Route::get('roles/{roleId}', [AccessControlController::class, 'showRole'])->whereNumber('roleId');
                    Route::put('roles/{roleId}/permissions', [AccessControlController::class, 'syncRolePermissions'])->whereNumber('roleId');
                    Route::delete('roles/{roleId}', [AccessControlController::class, 'destroyRole'])->whereNumber('roleId');
                    Route::put('users/{tenantUser}/direct-permissions', [AccessControlController::class, 'syncUserDirectPermissions']);
                });
                Route::prefix('locations')->group(function () {
                    Route::get('countries', [LocationController::class, 'countries']);
                    Route::get('regions', [LocationController::class, 'regions']);
                    Route::get('districts', [LocationController::class, 'districts']);
                    Route::get('wards', [LocationController::class, 'wards']);
                });
                Route::apiResource('property-types', PropertyTypeController::class);
                Route::apiResource('properties', PropertyController::class);
                Route::apiResource('property-floors', PropertyFloorController::class);
                Route::apiResource('units', UnitController::class);
                Route::prefix('maintenance')->group(function () {
                    Route::apiResource('jobs', MaintenanceJobController::class)
                        ->parameters(['jobs' => 'maintenanceJob']);
                    Route::apiResource('expenses', MaintenanceExpenseController::class)
                        ->parameters(['expenses' => 'maintenanceExpense']);
                });
                Route::apiResource('customers', CustomerController::class);
                Route::apiResource('customer-contracts', CustomerContractController::class);
                Route::apiResource('staff-property-assignments', StaffPropertyAssignmentController::class);
                Route::apiResource('staff-users', TenantUserController::class)
                    ->parameters(['staff-users' => 'tenantUser']);
            });
        });
});
