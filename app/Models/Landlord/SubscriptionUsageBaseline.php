<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionUsageBaseline extends BaseModel
{
    protected $connection = 'base';

    protected $table = 'subscription_usage_baselines';

    protected $casts = [
        'period_starts_at' => 'datetime',
        'period_ends_at' => 'datetime',
        'accounted_at' => 'datetime',
        'frequencies' => 'array',
        'meta' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenancy\Tenant::class, 'tenant_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function billingProfile(): BelongsTo
    {
        return $this->belongsTo(BillingProfile::class, 'billing_profile_id');
    }
}
