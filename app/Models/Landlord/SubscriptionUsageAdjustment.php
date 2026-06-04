<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionUsageAdjustment extends BaseModel
{
    public const REASON_USAGE_CHANGE = 'usage_change';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_WAIVED = 'waived';
    public const STATUS_SUPERSEDED = 'superseded';

    public const TYPE_CHARGE = 'charge';
    public const TYPE_CREDIT = 'credit';
    public const TYPE_NONE = 'none';

    protected $connection = 'base';

    protected $table = 'subscription_usage_adjustments';

    protected $casts = [
        'effective_at' => 'datetime',
        'applied_at' => 'datetime',
        'waived_at' => 'datetime',
        'period_starts_at' => 'datetime',
        'period_ends_at' => 'datetime',
        'baseline_frequencies' => 'array',
        'current_frequencies' => 'array',
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
