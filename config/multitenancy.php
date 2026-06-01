<?php

use Spatie\Multitenancy\Tasks\SwitchTenantDatabaseTask;

return [
    // The Eloquent model that represents a tenant (in the base database)
    'tenant_model' => App\Models\Tenancy\Tenant::class,

    // Name of the base (landlord) database connection
    'landlord_database_connection_name' => 'base',

    // The app connection that should be switched to the tenant's database
    'tenant_database_connection_name' => 'tenant',

    // How to find the current tenant for a request
    'tenant_finder' => App\Tenancy\HeaderTenantFinder::class,

    // Which tasks to run when making a tenant current
    'switch_tenant_tasks' => [
        SwitchTenantDatabaseTask::class,
    ],

    // Optional: key used to store current tenant in the container
    'current_tenant_container_key' => 'currentTenant',
];
