<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionProfileChange extends BaseModel
{
    protected $connection = 'base';

    protected $table = 'subscription_profile_changes';

    protected $casts = [
        'effective_at' => 'datetime',
        'applied_at' => 'datetime',
        'period_starts_at' => 'datetime',
        'period_ends_at' => 'datetime',
        'meta' => 'array',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function oldBillingProfile(): BelongsTo
    {
        return $this->belongsTo(BillingProfile::class, 'old_billing_profile_id');
    }

    public function newBillingProfile(): BelongsTo
    {
        return $this->belongsTo(BillingProfile::class, 'new_billing_profile_id');
    }
}
