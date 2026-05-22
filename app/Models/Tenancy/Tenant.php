<?php

namespace App\Models\Tenancy;

use Spatie\Multitenancy\Models\Tenant as SpatieTenant;

class Tenant extends SpatieTenant
{
    protected $connection = 'base';
    protected $table = 'tenants';

    protected $fillable = [
        'uuid',
        'name',
        'display_name',
        'database',
        'status',
        'provisioning_status',
        'provision_attempts',
        'provision_error',
        'provision_started_at',
        'provisioned_at',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'provision_started_at' => 'datetime',
        'provisioned_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
