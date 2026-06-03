<?php

namespace App\Providers;

use App\Models\Tenant\Permission as TenantPermission;
use App\Models\Tenant\Role as TenantRole;
use App\Models\Tenant\Country;
use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerContract;
use App\Models\Tenant\District;
use App\Models\Tenant\MaintenanceExpense;
use App\Models\Tenant\MaintenanceJob;
use App\Models\Tenant\Property;
use App\Models\Tenant\PropertyFloor;
use App\Models\Tenant\PropertyType;
use App\Models\Tenant\Region;
use App\Models\Tenant\StaffPropertyAssignment;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Ward;
use App\Policies\LocationPolicy;
use App\Policies\CustomerContractPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\MaintenanceExpensePolicy;
use App\Policies\MaintenanceJobPolicy;
use App\Policies\PropertyPolicy;
use App\Policies\PropertyFloorPolicy;
use App\Policies\PropertyTypePolicy;
use App\Policies\StaffPropertyAssignmentPolicy;
use App\Policies\TenantUserPolicy;
use App\Policies\UnitPolicy;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        config([
            'permission.models.role' => TenantRole::class,
            'permission.models.permission' => TenantPermission::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Property::class, PropertyPolicy::class);
        Gate::policy(Country::class, LocationPolicy::class);
        Gate::policy(Region::class, LocationPolicy::class);
        Gate::policy(District::class, LocationPolicy::class);
        Gate::policy(Ward::class, LocationPolicy::class);
        Gate::policy(PropertyType::class, PropertyTypePolicy::class);
        Gate::policy(PropertyFloor::class, PropertyFloorPolicy::class);
        Gate::policy(Unit::class, UnitPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(CustomerContract::class, CustomerContractPolicy::class);
        Gate::policy(MaintenanceJob::class, MaintenanceJobPolicy::class);
        Gate::policy(MaintenanceExpense::class, MaintenanceExpensePolicy::class);
        Gate::policy(StaffPropertyAssignment::class, StaffPropertyAssignmentPolicy::class);
        Gate::policy(TenantUser::class, TenantUserPolicy::class);

        RateLimiter::for('api', function (Request $request) {
            $tenant = (string) $request->header('X-Tenant-Id', 'na');
            $uid = optional($request->user())->uuid ?? optional($request->user())->id;
            $key = 't:'.$tenant.'|'.($uid ? ('u:'.$uid) : ('ip:'.$request->ip()));
            return Limit::perMinute(60)->by($key);
        });

        RateLimiter::for('login', function (Request $request) {
            $identifier = (string) ($request->input('email') ?? $request->input('phone') ?? 'guest');
            return [
                Limit::perMinute(5)->by($identifier.'|'.$request->ip())->response(function () {
                    return ApiResponse::tooManyRequests(
                        ['auth' => [ApiMessages::TOO_MANY_REQUESTS]],
                        'Too many sign-in attempts were made. Please wait a moment and try again.'
                    );
                }),
            ];
        });

        RateLimiter::for('refresh', function (Request $request) {
            $tenant = (string) $request->header('X-Tenant-Id', 'na');
            $uid = optional($request->user())->uuid ?? optional($request->user())->id;
            $key = 't:'.$tenant.'|'.($uid ? ('u:'.$uid) : ('ip:'.$request->ip()));
            return Limit::perMinute(30)->by($key);
        });
    }
}
