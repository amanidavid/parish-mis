<?php

namespace App\Models\Landlord;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionTrialExtension extends BaseModel
{
    protected $connection = 'base';

    protected $table = 'subscription_trial_extensions';

    protected $casts = [
        'old_trial_ends_at' => 'datetime',
        'new_trial_ends_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenancy\Tenant::class, 'tenant_id');
    }

    public function extendedBy(): BelongsTo
    {
        return $this->belongsTo(BaseUser::class, 'extended_by_user_id');
    }
}
