<?php

use App\Http\Controllers\Api\Admin\V1\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\V1\BillingProfileController;
use App\Http\Controllers\Api\Admin\V1\TenantController;
use App\Http\Controllers\Api\App\V1\AccessControlController;
use App\Http\Controllers\Api\App\V1\AuthController as AppAuthController;
use App\Http\Controllers\Api\App\V1\CustomerContractController;
use App\Http\Controllers\Api\App\V1\CustomerController;
use App\Http\Controllers\Api\App\V1\LocationController;
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
                Route::post('logout', [AdminAuthController::class, 'logout'])->middleware('admin.jwt.auth');
                Route::get('me', [AdminAuthController::class, 'me'])->middleware('admin.jwt.auth');
            });

            Route::middleware('admin.jwt.auth')->group(function () {
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
                Route::get('tenants/{tenant}/subscription', [TenantController::class, 'subscription']);
                Route::get('tenants/{tenant}/subscription/properties', [TenantController::class, 'subscriptionProperties']);
                Route::patch('tenants/{tenant}/billing-profile', [TenantController::class, 'assignBillingProfile']);
                Route::patch('tenants/{tenant}/status', [TenantController::class, 'updateStatus']);
                Route::patch('tenants/{tenant}/subscription-status', [TenantController::class, 'updateSubscriptionStatus']);
                Route::post('tenants/{tenant}/retry-provisioning', [TenantController::class, 'retryProvisioning']);
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
                Route::get('workspace/subscription', [WorkspaceController::class, 'subscription']);
                Route::get('workspace/subscription/properties', [WorkspaceController::class, 'subscriptionProperties']);
                Route::prefix('access-control')->group(function () {
                    Route::get('permissions', [AccessControlController::class, 'permissions']);
                    Route::post('permissions', [AccessControlController::class, 'storePermission']);
                    Route::get('roles', [AccessControlController::class, 'roles']);
                    Route::get('roles/{roleId}', [AccessControlController::class, 'showRole'])->whereNumber('roleId');
                    Route::put('roles/{roleId}/permissions', [AccessControlController::class, 'syncRolePermissions'])->whereNumber('roleId');
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
                Route::apiResource('customers', CustomerController::class);
                Route::apiResource('customer-contracts', CustomerContractController::class);
                Route::apiResource('staff-property-assignments', StaffPropertyAssignmentController::class);
                Route::apiResource('staff-users', TenantUserController::class);
            });
        });
});
