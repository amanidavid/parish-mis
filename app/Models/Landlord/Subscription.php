<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends BaseModel
{
    protected $connection = 'base';

    protected $table = 'subscriptions';

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'meta' => 'array',
    ];

    public function billingProfile(): BelongsTo
    {
        return $this->belongsTo(BillingProfile::class, 'billing_profile_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function profileChanges(): HasMany
    {
        return $this->hasMany(SubscriptionProfileChange::class, 'subscription_id');
    }

    public function usageBaselines(): HasMany
    {
        return $this->hasMany(SubscriptionUsageBaseline::class, 'subscription_id');
    }

    public function usageAdjustments(): HasMany
    {
        return $this->hasMany(SubscriptionUsageAdjustment::class, 'subscription_id');
    }
}
