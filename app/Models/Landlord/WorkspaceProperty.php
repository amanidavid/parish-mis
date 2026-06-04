<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use App\Models\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkspaceProperty extends BaseModel
{
    protected $connection = 'base';

    protected $table = 'workspace_properties';

    protected $casts = [
        'property_created_at' => 'datetime',
        'property_updated_at' => 'datetime',
        'property_deleted_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'meta' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(PropertySubscription::class, 'workspace_property_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PropertySubscriptionPayment::class, 'workspace_property_id');
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(PropertySubscriptionPayment::class, 'workspace_property_id')->latestOfMany();
    }
}
